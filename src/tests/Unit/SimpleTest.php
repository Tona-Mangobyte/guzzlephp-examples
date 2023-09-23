<?php

namespace Tests\Unit;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class SimpleTest extends TestCase
{
    public function testOneTodoRequest() {
        $client = $this->getClient();
        $response = $client->get('todos/1', [
            'headers' => [
                'User-Agent' => 'testing/1.0',
                'Accept'     => 'application/json',
                'X-Foo'      => ['Bar', 'Baz']
            ]
        ]);
        print_r($response->hasHeader('X-Foo'));
        $data = json_decode($response->getBody()->getContents());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $data->id);
        $this->assertEquals(1, $data->userId);
    }

    public function testOneTodoRequestWithMiddleware() {
        // Create a middleware that echoes parts of the request.
        $tapMiddleware = Middleware::tap(function ($request) {
            echo $request->getHeaderLine('Content-Type');
            // application/json
            echo $request->getBody();
            // {"foo":"bar"}
        });

        $client = $this->getClient();
        $response = $client->get('todos/1', [
            'headers' => [
                'User-Agent' => 'testing/1.0',
                'Accept'     => 'application/json',
                'X-Foo'      => ['Bar', 'Baz']
            ],
            // 'handler' => $tapMiddleware($handler)
        ]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testOneTodoRequest2() {
        $request = new Request('GET', 'https://jsonplaceholder.typicode.com/todos/1');
        /*print_r($request->getBody()->getContents());
        print_r($request->getUri()->getScheme());*/
        $this->assertTrue(true);
    }

    public function testRequestWithHandlerStack() {
        $stack = new HandlerStack();
        $stack->setHandler(new CurlHandler());
        $stack->push($this->add_header('X-Foo', 'bar'));
        $stack->push($this->add_response_header('X-Foo', 'bar'));
        // mapRequest ALWAYS adds a Authentication header (if Request contains a "X-Token: Add" header
        // getClientAccessToken() should get the current token from the storage/cache and add it
        $stack->push(Middleware::mapRequest(function (Request $request) {
            if ($request->hasHeader('X-Token')) {
                print_r("execute \n");
                return $request->withHeader('Authorization', 'Bearer ' . $this->getClientAccessToken());
            }
            return $request;
        }));
        $stack->push(Middleware::retry(
            function (
                $retries,
                Request $request,
                Response $response = null,
                RequestException $exception = null
            ) {
                $maxRetries = 1;

                if ($retries >= $maxRetries) {
                    return false;
                }

                if ($response && $response->getStatusCode() === 401) {
                    // received 401, so we need to refresh the token
                    // this should call your custom function that requests a new token and stores it somewhere (cache)
                    $this->refreshClientAccessToken();
                    return true;
                }

                return false;
            }
        ));

        $client = $this->getClient();
        $response = $client->get('todos/1', [
            'handler' => $stack,
        ]);
        // print_r($response->getHeaders());
        $this->assertEquals(200, $response->getStatusCode());
    }

    private function getClient() {
        return new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://jsonplaceholder.typicode.com/',
            // You can set any number of default request options.
            'timeout'  => 2.0,
            'verify' => false,
            'headers' => [
                'x-token' => '32232',
                'X-Token' => 'Add',
            ]
        ]);
    }

    function add_header($header, $value)
    {
        return function (callable $handler) use ($header, $value) {
            print_r("$header => $value \n");
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $request = $request->withHeader($header, $value);
                return $handler($request, $options);
            };
        };
    }

    function add_response_header($header, $value)
    {
        return function (callable $handler) use ($header, $value) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $promise = $handler($request, $options);
                return $promise->then(
                    function (ResponseInterface $response) use ($header, $value) {
                        return $response->withHeader($header, $value);
                    }
                );
            };
        };
    }

    function getClientAccessToken () {
        return "211323232";
    }

    function refreshClientAccessToken() {
        echo "execute refresh token access api \n";
    }
}