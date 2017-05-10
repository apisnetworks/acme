<?php

/**
 * This file is part of the ACME package.
 *
 * @copyright Copyright (c) 2015-2016, Niklas Keller
 * @license MIT
 */

namespace Kelunik\Acme;

use Amp\Artax\Client;
use Amp\Artax\Cookie\NullCookieJar;
use Amp\Artax\HttpClient;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\CoroutineResult;
use Amp\Deferred;
use Amp\Failure;
use Amp\Success;
use Exception;
use InvalidArgumentException;
use Namshi\JOSE\Base64\Base64UrlSafeEncoder;
use Namshi\JOSE\SimpleJWS;
use Throwable;

/**
 * Low level ACME client.
 *
 * @author Niklas Keller <me@kelunik.com>
 * @package Kelunik\Acme
 */
class AcmeClient {
    /**
     * @var HttpClient HTTP client
     */
    private $http;

    /**
     * @var KeyPair account key pair
     */
    private $keyPair;

    /**
     * @var string directory URI of the ACME server
     */
    private $directoryUri;

    /**
     * @var array dictionary contents of the ACME server
     */
    private $directory;

    /**
     * @var array saved nonces for use in future requests
     */
    private $nonces;

    /**
     * AcmeClient constructor.
     *
     * @api
     * @param string      $directoryUri URI to the ACME server directory
     * @param KeyPair     $keyPair account key pair
     * @param HttpClient|null $http custom HTTP client, default client will be used if no value is provided
     */
    public function __construct($directoryUri, KeyPair $keyPair, HttpClient $http = null) {
        if (!is_string($directoryUri)) {
            throw new InvalidArgumentException(sprintf("\$directoryUri must be of type string, %s given.", gettype($directoryUri)));
        }

        $this->directoryUri = $directoryUri;
        $this->keyPair = $keyPair;
        $this->http = $http ?: $this->buildClient();
        $this->nonces = [];
    }

    /**
     * Constructs a default HTTP client.
     *
     * @return Client
     */
    private function buildClient() {
        $client = new Client(new NullCookieJar);
        $client->setOption(Client::OP_DEFAULT_USER_AGENT, "kelunik/acme");
        return $client;
    }

    /**
     * Pops a locally stored nonce or requests a new one for usage.
     *
     * @param string $uri URI to issue the HEAD request against if no nonce is stored locally.
     * @return \Amp\Promise resolves to the nonce value
     */
    private function getNonce($uri) {
        if (!is_string($uri)) {
            throw new InvalidArgumentException(sprintf("\$uri must be of type string, %s given.", gettype($uri)));
        }

        if (empty($this->nonces)) {
            return $this->requestNonce($uri);
        }

        return new Success(array_shift($this->nonces));
    }

    /**
     * Requests a new request nonce from the server.
     *
     * @param string $uri URI to issue the HEAD request against
     * @return \Amp\Promise resolves to the retrieved nonce
     */
    private function requestNonce($uri) {
        if (!is_string($uri)) {
            throw new InvalidArgumentException(sprintf("\$uri must be of type string, %s given.", gettype($uri)));
        }

        $deferred = new Deferred;
        $request = (new Request)->setMethod("HEAD")->setUri($uri);

        $this->http->request($request)->when(function ($error = null, Response $response = null) use ($deferred, $uri) {
            if ($error) {
                /** @var Throwable|Exception $error */
                $deferred->fail(new AcmeException("HEAD request to {$uri} failed, could not obtain a replay nonce: " . $error->getMessage(), null, $error));
            } else {
                if (!$response->hasHeader("replay-nonce")) {
                    $deferred->fail(new AcmeException("HTTP response didn't carry replay-nonce header."));
                } else {
                    list($nonce) = $response->getHeader("replay-nonce");
                    $deferred->succeed($nonce);
                }
            }
        });

        return $deferred->promise();
    }

    /**
     * Returns the URI to a resource by querying the directory. Can also handle URIs and returns them directly.
     *
     * @param string $resource URI or directory entry
     * @return \Amp\Promise resolves to the resource URI
     * @throws AcmeException If the specified resource is not in the directory.
     */
    private function getResourceUri($resource) {
        if (!is_string($resource)) {
            throw new InvalidArgumentException(sprintf("\$resource must be of type string, %s given.", gettype($resource)));
        }

        // ACME MUST be served over HTTPS, but we use HTTP for testing …
        if (substr($resource, 0, 7) === "http://" || substr($resource, 0, 8) === "https://") {
            return new Success($resource);
        }

        if (!$this->directory) {
            return \Amp\pipe(\Amp\resolve($this->fetchDirectory()), function () use ($resource) {
                return $this->getResourceUri($resource);
            });
        }

        if (isset($this->directory[$resource])) {
            return new Success($this->directory[$resource]);
        }

        return new Failure(new AcmeException("Resource not found in directory: '{$resource}'."));
    }

    /**
     * Retrieves the directory and stores it in the directory property.
     *
     * @return \Generator coroutine resolved by Amp.
     * @throws AcmeException If the directory could not be fetched or was invalid.
     */
    private function fetchDirectory() {
        try {
            /** @var Response $response */
            $response = (yield $this->http->request($this->directoryUri));

            if ($response->getStatus() !== 200) {
                $info = json_decode($response->getBody());

                if (isset($info->type, $info->detail)) {
                    throw new AcmeException("Invalid directory response: {$info->detail}", $info->type);
                }

                throw new AcmeException("Invalid directory response. HTTP response code: " . $response->getStatus());
            }

            $directory = json_decode($response->getBody(), true);

            if (empty($directory)) {
                throw new AcmeException("Invalid directory: empty!");
            }

            $this->directory = $directory;
            $this->saveNonce($response);
        } catch (Exception $e) {
            throw new AcmeException("Could not obtain directory: " . $e->getMessage(), null, $e);
        } catch (Throwable $e) {
            throw new AcmeException("Could not obtain directory: " . $e->getMessage(), null, $e);
        }
    }

    /**
     * Retrieves a resource using a GET request.
     *
     * @api
     * @param string $resource resource to fetch
     * @return \Amp\Promise resolves to the HTTP response
     * @throws AcmeException If the request failed.
     */
    public function get($resource) {
        return \Amp\resolve($this->doGet($resource));
    }

    /**
     * Retrieves a resource using a GET request.
     *
     * @param string $resource resource to fetch
     * @return \Generator coroutine resolved by Amp returning the HTTP response
     * @throws AcmeException If the request failed.
     */
    private function doGet($resource) {
        if (!is_string($resource)) {
            throw new InvalidArgumentException(sprintf("\$resource must be of type string, %s given.", gettype($resource)));
        }

        $uri = (yield $this->getResourceUri($resource));

        try {
            $response = (yield $this->http->request($uri));
            $this->saveNonce($response);
        } catch (Throwable $e) {
            throw new AcmeException("GET request to {$uri} failed: " . $e->getMessage(), null, $e);
        } catch (Exception $e) {
            throw new AcmeException("GET request to {$uri} failed: " . $e->getMessage(), null, $e);
        }

        yield new CoroutineResult($response);
    }

    /**
     * Retrieves a resource using a POST request.
     *
     * @api
     * @param string $resource resource to fetch
     * @param array  $payload
     * @return \Amp\Promise resolves to the HTTP response
     * @throws AcmeException If the request failed.
     */
    public function post($resource, array $payload) {
        return \Amp\resolve($this->doPost($resource, $payload));
    }

    /**
     * Retrieves a resource using a POST request.
     *
     * @param string $resource resource to fetch
     * @param array  $payload
     * @return \Generator coroutine resolved by Amp returning the HTTP response
     * @throws AcmeException If the request failed.
     */
    private function doPost($resource, array $payload) {
        if (!is_string($resource)) {
            throw new InvalidArgumentException(sprintf("\$resource must be of type string, %s given.", gettype($resource)));
        }

        $privateKey = openssl_pkey_get_private($this->keyPair->getPrivate());
        $details = openssl_pkey_get_details($privateKey);

        if ($details["type"] !== OPENSSL_KEYTYPE_RSA) {
            throw new \RuntimeException("Only RSA keys are supported right now.");
        }

        $uri = (yield $this->getResourceUri($resource));

        $attempt = 0;
        $statusCode = null;

        do {
            $attempt++;

            if ($attempt > 3) {
                throw new AcmeException("POST request to {$uri} failed, received too many errors (last code: ${statusCode}).");
            }
            
            $enc = new Base64UrlSafeEncoder();
            $jws = new SimpleJWS([
                "alg" => "RS256",
                "jwk" => [
                    "kty" => "RSA",
                    "n" => $enc->encode($details["rsa"]["n"]),
                    "e" => $enc->encode($details["rsa"]["e"]),
                ],
                "nonce" => (yield $this->getNonce($uri)),
            ]);

            $payload["resource"] = isset($payload["resource"]) ? $payload["resource"] : $resource;

            $jws->setPayload($payload);
            $jws->sign($privateKey);

            $request = (new Request)->setMethod("POST")->setUri($uri)->setBody($jws->getTokenString());

            try {
                /** @var Response $response */
                $response = (yield $this->http->request($request));
                $this->saveNonce($response);
                $statusCode = $response->getStatus();
                if ($statusCode === 400) {
                    $info = json_decode($response->getBody());

                    if (!empty($info->type) && ($info->type === "urn:acme:badNonce" or $info->type === "urn:acme:error:badNonce")) {
                        continue;
                    }
                } else if ($statusCode === 429) {
                    /**
                     * Hit rate limit
                     * @{link} https://letsencrypt.org/docs/rate-limits/
                     */
                    sleep(1);
                    continue;
                }
            } catch (Throwable $e) {
                throw new AcmeException("POST request to {$uri} failed: " . $e->getMessage(), null, $e);
            } catch (Exception $e) {
                throw new AcmeException("POST request to {$uri} failed: " . $e->getMessage(), null, $e);
            }

            yield new CoroutineResult($response);
            return;
        } while (true);
    }

    /**
     * Saves the nonce if one was provided in the response.
     *
     * @param Response $response response which may carry a new replay nonce as header
     */
    private function saveNonce(Response $response) {
        if (!$response->hasHeader("replay-nonce")) {
            return;
        }

        list($nonce) = $response->getHeader("replay-nonce");
        $this->nonces[] = $nonce;
    }
}
