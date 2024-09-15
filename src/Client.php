<?php

namespace Jalno\Http;

use Jalno\Http\Contracts\IHandler;
use Jalno\Http\Contracts\IRequest;
use Jalno\Http\Contracts\IResponse;
use Jalno\Http\Exceptions\ClientException;
use Jalno\Http\Exceptions\Exception;
use Jalno\Http\Exceptions\ResponseException;
use Jalno\Http\Exceptions\ServerException;
use TypeError;

/**
 * @phpstan-import-type Options from IHandler
 */
class Client implements IHandler
{
    /**
     * @var Options
     */
    private static array $defaultOptions = [
        'allow_redirects' => true,
        'connect_timeout' => 0,
        'debug' => false,
        'delay' => 0,
        'http_errors' => true,
        'ssl_verify' => true,
        'timeout' => 0,
    ];

    /**
     * @var Options
     */
    protected array $options;

    protected IHandler $handler;

    /**
     * @param Options $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_replace_recursive(self::$defaultOptions, $options);
        $this->handler = new CurlHandler();
    }

    public function getHandler(): IHandler
    {
        return $this->handler;
    }

    public function setHandler(IHandler $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * @param Options $options
     */
    public function request(string $method, string $URI, array $options = []): IResponse
    {
        $options = $this->mergeOptions($options);
        $request = Request::fromOptions($method, $URI, $options);
        $response = $this->fire($request, $options);

        $status = $response->getStatusCode();
        if ($status >= 400 and $status < 500) {
            throw new ClientException($request, $response);
        } elseif ($status >= 500 and $status < 600) {
            throw new ServerException($request, $response);
        } elseif ($status >= 600) {
            throw new ResponseException($request, $response);
        }

        return $response;
    }

    /**
     * @param Options $options
     */
    public function get(string $URI, array $options = []): IResponse
    {
        return $this->request('get', $URI, $options);
    }

    /**
     * @param Options $options
     */
    public function post(string $URI, array $options = []): IResponse
    {
        return $this->request('post', $URI, $options);
    }

    public function fire(IRequest $request, array $options): IResponse
    {
        if (isset($options['delay']) and $options['delay'] > 0) {
            usleep($options['delay']);
        }

        return $this->handler->fire($request, $options);
    }

    /**
     * @param Options $options
     *
     * @return Options
     */
    protected function mergeOptions(array $options): array
    {
        $options = array_replace($this->options, $options);
        if (isset($options['auth']) and $options['auth']) {
            if (!isset($options['headers']['authorization'])) {
                if (is_array($options['auth'])) {
                    $options['headers']['authorization'] = 'Basic '.base64_encode($options['auth']['username'].':'.$options['auth']['password']);
                } else {
                    $options['headers']['authorization'] = $options['auth'];
                }
            }
        }
        if (isset($options['json']) and $options['json']) {
            $options['headers']['content-type'] = 'application/json; charset=UTF-8';
            if (!$options['body']) {
                $options['body'] = json_encode($options['json'], JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($options['form_params']) and $options['form_params']) {
            $options['headers']['content-type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            if (!$options['body']) {
                $options['body'] = http_build_query($options['form_params']);
            }
        }
        if (isset($options['multipart']) and $options['multipart']) {
            $options['headers']['content-type'] = 'multipart/form-data; charset=UTF-8';
            if (!$options['body']) {
                $options['body'] = $options['multipart'];
            }
        }
        if (isset($options['proxy'])) {
            if (is_string($options['proxy'])) {
                $proxy = parse_url($options['proxy']);
                if (false === $proxy) {
                    throw new Exception('cannot parse proxy');
                }
                if (!isset($proxy['host'])) {
                    throw new Exception('host is not present in proxy url');
                }
                if (!isset($proxy['port'])) {
                    throw new Exception('port is not present in proxy url');
                }
                $proxyAsArray = [
                    'type' => $proxy['scheme'] ?? 'http',
                    'hostname' => $proxy['host'],
                    'port' => $proxy['port'],
                ];
                $options['proxy'] = $proxyAsArray;
            }
            if (is_array($options['proxy'])) {
                if (!isset($options['proxy']['type']) or !is_string($options['proxy']['type']) or !in_array($options['proxy']['type'], ['http', 'https', 'socks4', 'socks5'])) {
                    throw new TypeError('proxy type is invalid');
                }
                if (!isset($options['proxy']['hostname']) or !is_string($options['proxy']['hostname'])) {
                    throw new TypeError('proxy hostname is invalid');
                }
                if (!isset($options['proxy']['port']) or !is_numeric($options['proxy']['port']) or $options['proxy']['port'] < 0 or $options['proxy']['port'] > 65535) {
                    throw new TypeError('proxy port is invalid');
                }
            } else {
                throw new TypeError('proxy passed to '.__NAMESPACE__.'\\'.__CLASS__.'::'.__METHOD__.'() must be of the type array');
            }
        }

        return $options;
    }
}
