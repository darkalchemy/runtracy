<?php

declare(strict_types=1);

namespace RunTracy\Helpers\Console;

/**
 * JSON RPC Server for Eaze.
 *
 * Reads $_GET['rawRequest'] or php://input for Request Data
 *
 * @see       http://www.jsonrpc.org/specification
 * @see       http://dojotoolkit.org/reference-guide/1.8/dojox/rpc/smd.html
 *
 * @author     Sergeyfast
 */
class BaseJsonRpcServer
{
    protected const PARSEERROR     = -32700;
    protected const INVALIDREQUEST = -32600;
    protected const METHODNOTFOUND = -32601;
    protected const INVALIDPARAMS  = -32602;
    protected const INTERNALERROR  = -32603;

    /**
     * Content Type.
     *
     * @var string
     */
    public $ContentType = 'application/json';

    /**
     * Allow Cross-Domain Requests.
     *
     * @var bool
     */
    public $IsXDR = true;

    /**
     * Max Batch Calls.
     *
     * @var int
     */
    public $MaxBatchCalls = 10;

    /**
     * Exposed Instances.
     *
     * @var object[] namespace => method
     */
    protected $instances = [];

    /**
     * Decoded Json Request.
     *
     * @var array|object
     */
    protected $request;

    /**
     * Array of Received Calls.
     *
     * @var array
     */
    protected $calls = [];

    /**
     * Array of Responses for Calls.
     *
     * @var array
     */
    protected $response = [];

    /**
     * Has Calls Flag (not notifications).
     *
     * @var bool
     */
    protected $hasCalls = false;

    /**
     * Hidden Methods.
     *
     * @var array
     */
    protected $hiddenMethods = [
        'execute', '__construct', 'registerinstance',
    ];

    /**
     * Error Messages.
     *
     * @var array
     */
    protected $errorMessages = [
        self::PARSEERROR     => 'Parse error',
        self::INVALIDREQUEST => 'Invalid Request',
        self::METHODNOTFOUND => 'Method not found',
        self::INVALIDPARAMS  => 'Invalid params',
        self::INTERNALERROR  => 'Internal error',
    ];

    /**
     * Is Batch Call in using.
     *
     * @var bool
     */
    private $isBatchCall = false;

    /**
     * Cached Reflection Methods.
     *
     * @var \ReflectionMethod[]
     */
    private $reflectionMethods = [];

    /**
     * Create new Instance.
     *
     * @param object $instance
     */
    public function __construct($instance = null)
    {
        if (get_parent_class($this)) {
            $this->registerInstance($this, '');
        } elseif ($instance) {
            $this->registerInstance($instance, '');
        }
    }

    /**
     * Register Instance.
     *
     * @param object $instance
     * @param string $namespace default is empty string
     *
     * @return $this
     */
    public function registerInstance($instance, $namespace = '')
    {
        $this->instances[$namespace] = $instance;
        if (is_object($this->instances[$namespace])) {
            $this->instances[$namespace]->errorMessages = $this->errorMessages;
        }

        return $this;
    }

    /**
     * Get Instances.
     *
     * @return \object[]
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     * Handle Requests.
     */
    public function execute()
    {
        $ret = [];
        do {
            // check for SMD Discovery request
            if (array_key_exists('smd', $_GET)) {
                $this->response[] = $this->getServiceMap();
                $this->hasCalls   = true;

                break;
            }

            $error = $this->getRequest();
            if ($error) {
                $this->response[] = $this->getError($error);
                $this->hasCalls   = true;

                break;
            }

            foreach ($this->calls as $call) {
                $error = $this->validateCall($call);
                if ($error) {
                    $this->response[] = $this->getError($error[0], $error[1], $error[2]);
                    $this->hasCalls   = true;
                } else {
                    $result = $this->processCall($call);
                    if ($result) {
                        $this->response[] = $result;
                        $this->hasCalls   = true;
                    }
                }
            }
        } while (false);

        // flush response
        if ($this->hasCalls) {
            if (!$this->isBatchCall) {
                $this->response = reset($this->response);
            }

            if (!headers_sent()) {
                // Allow Cross Domain Requests
                if ($this->IsXDR) {
                    header('Access-Control-Allow-Origin: *');
                    header('Access-Control-Allow-Headers: x-requested-with, content-type');
                }
            }

            $ret = $this->response;
            $this->resetVars();
        }

        return $ret;
    }

    /**
     * Validate Request.
     *
     * @return int error
     */
    private function getRequest()
    {
        $error = null;

        do {
            if (array_key_exists('REQUEST_METHOD', $_SERVER) && $_SERVER['REQUEST_METHOD'] != 'POST') {
                $error = self::INVALIDREQUEST;

                break;
            }

            $request       = !empty($_GET['rawRequest']) ? $_GET['rawRequest'] : file_get_contents('php://input');
            $this->request = json_decode($request, false);
            if ($this->request === null) {
                $error = self::PARSEERROR;

                break;
            }

            if ($this->request === []) {
                $error = self::INVALIDREQUEST;

                break;
            }

            // check for batch call
            if (is_array($this->request)) {
                if (count($this->request) > $this->MaxBatchCalls) {
                    $error = self::INVALIDREQUEST;

                    break;
                }

                $this->calls       = $this->request;
                $this->isBatchCall = true;
            } else {
                $this->calls[] = $this->request;
            }
        } while (false);

        return $error;
    }

    /**
     * Get Error Response.
     *
     * @param int   $code
     * @param mixed $id
     * @param null  $data
     *
     * @return array
     */
    private function getError($code, $id = null, $data = null)
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $this->errorMessages[$code] ?? $this->errorMessages[self::INTERNALERROR],
                'data'    => $data,
            ],
        ];
    }

    /**
     * Check for jsonrpc version and correct method.
     *
     * @param \stdClass $call
     *
     * @return null|array
     */
    private function validateCall(\stdClass $call)
    {
        $result = null;
        $error  = null;
        $data   = null;
        $id     = is_object($call) && property_exists($call, 'id') ? $call->id : null;
        do {
            if (!is_object($call)) {
                $error = self::INVALIDREQUEST;

                break;
            }

            // hack for inputEx smd tester
            if (property_exists($call, 'version')) {
                if ($call->version == 'json-rpc-2.0') {
                    $call->jsonrpc = '2.0';
                }
            }

            if (!property_exists($call, 'jsonrpc') || $call->jsonrpc != '2.0') {
                $error = self::INVALIDREQUEST;

                break;
            }

            $fullMethod = property_exists($call, 'method') ? $call->method : '';
            $methodInfo = explode('.', $fullMethod, 2);
            $namespace  = array_key_exists(1, $methodInfo) ? $methodInfo[0] : '';
            $method     = $namespace ? $methodInfo[1] : $fullMethod;
            if (
                !$method || !array_key_exists($namespace, $this->instances)
                || !method_exists($this->instances[$namespace], $method)
                || in_array(strtolower($method), $this->hiddenMethods)
            ) {
                $error = self::METHODNOTFOUND;

                break;
            }

            if (!array_key_exists($fullMethod, $this->reflectionMethods)) {
                $this->reflectionMethods[$fullMethod] = new \ReflectionMethod($this->instances[$namespace], $method);
            }

            /** @var array $params */
            $params     = property_exists($call, 'params') ? $call->params : null;
            $paramsType = gettype($params);
            if ($params !== null && $paramsType != 'array' && $paramsType != 'object') {
                $error = self::INVALIDPARAMS;
                $data  = 'Cast of params error';

                break;
            }

            // check parameters
            switch ($paramsType) {
                case 'array':
                    $totalRequired = 0;
                    // doesn't hold required, null, required sequence of params
                    foreach ($this->reflectionMethods[$fullMethod]->getParameters() as $param) {
                        if (!$param->isDefaultValueAvailable()) {
                            ++$totalRequired;
                        }
                    }

                    if (count($params) < $totalRequired) {
                        $error = self::INVALIDPARAMS;
                        $data  = sprintf(
                            'Check numbers of required params (got %d, expected %d)',
                            count($params),
                            $totalRequired
                        );
                    }

                    break;

                case 'object':
                    foreach ($this->reflectionMethods[$fullMethod]->getParameters() as $param) {
                        if (!$param->isDefaultValueAvailable() && !array_key_exists($param->getName(), $params)) {
                            $error = self::INVALIDPARAMS;
                            $data  = $param->getName() . ' not found';

                            break 3;
                        }
                    }

                    break;

                case 'NULL':
                    if ($this->reflectionMethods[$fullMethod]->getNumberOfRequiredParameters() > 0) {
                        $error = self::INVALIDPARAMS;
                        $data  = 'Empty required params';

                        break 2;
                    }

                    break;
            }
        } while (false);

        if ($error) {
            $result = [$error, $id, $data];
        }

        return $result;
    }

    /**
     * Process Call.
     *
     * @param \stdClass $call
     *
     * @return null|array
     */
    private function processCall(\stdClass $call)
    {
        $id        = property_exists($call, 'id') ? $call->id : null;
        $params    = property_exists($call, 'params') ? $call->params : [];
        $result    = null;
        $namespace = substr($call->method, 0, strpos($call->method, '.'));

        try {
            // set named parameters
            if (is_object($params)) {
                $newParams = [];
                foreach ($this->reflectionMethods[$call->method]->getParameters() as $param) {
                    $paramName    = $param->getName();
                    $defaultValue = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                    $newParams[]  = property_exists($params, $paramName) ? $params->{$paramName} : $defaultValue;
                }

                $params = $newParams;
            }

            // invoke
            $result = $this->reflectionMethods[$call->method]->invokeArgs($this->instances[$namespace], $params);
        } catch (\Exception $e) {
            return $this->getError($e->getCode(), $id, $e->getMessage());
        }

        if (!$id && $id !== 0) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'result'  => $result,
            'id'      => $id,
        ];
    }

    /**
     * Get Doc Comment.
     *
     * @param $comment
     *
     * @return null|string
     */
    private function getDocDescription($comment)
    {
        $result = null;
        if (preg_match('/\*\s+([^@]*)\s+/s', $comment, $matches)) {
            $result = str_replace('*', "\n", trim(trim($matches[1], '*')));
        }

        return $result;
    }

    /**
     * Get Service Map
     * Maybe not so good realization of auto-discover via doc blocks.
     *
     * @return array
     */
    private function getServiceMap()
    {
        $result = [
            'transport'   => 'POST',
            'envelope'    => 'JSON-RPC-2.0',
            'SMDVersion'  => '2.0',
            'contentType' => 'application/json',
            'target'      => !empty($_SERVER['REQUEST_URI']) ?
                substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?')) : '',
            'services'    => [],
            'description' => '',
        ];

        foreach ($this->instances as $namespace => $instance) {
            $rc = new \ReflectionClass($instance);

            // Get Class Description
            if ($rcDocComment = $this->getDocDescription($rc->getDocComment())) {
                $result['description'] .= $rcDocComment . \PHP_EOL;
            }

            foreach ($rc->getMethods() as $method) {
                /** @var \ReflectionMethod $method */
                if (!$method->isPublic() || in_array(strtolower($method->getName()), $this->hiddenMethods)) {
                    continue;
                }

                $methodName = ($namespace ? $namespace . '.' : '') . $method->getName();
                $docComment = $method->getDocComment();

                $result['services'][$methodName] = ['parameters' => []];

                // set description
                if ($rmDocComment = $this->getDocDescription($docComment)) {
                    $result['services'][$methodName]['description'] = $rmDocComment;
                }

                // @param\s+([^\s]*)\s+([^\s]*)\s*([^\s\*]*)
                $parsedParams = [];
                if (preg_match_all('/@param\s+([^\s]*)\s+([^\s]*)\s*([^\n\*]*)/', $docComment, $matches)) {
                    foreach ($matches[2] as $number => $name) {
                        $type = $matches[1][$number];
                        $desc = $matches[3][$number];
                        $name = trim($name, '$');

                        $param               = ['type' => $type, 'description' => $desc];
                        $parsedParams[$name] = array_filter($param);
                    }
                }

                // process params
                foreach ($method->getParameters() as $parameter) {
                    $name  = $parameter->getName();
                    $param = ['name' => $name, 'optional' => $parameter->isDefaultValueAvailable()];
                    if (array_key_exists($name, $parsedParams)) {
                        $param += $parsedParams[$name];
                    }

                    if ($param['optional']) {
                        $param['default'] = $parameter->getDefaultValue();
                    }

                    $result['services'][$methodName]['parameters'][] = $param;
                }

                // set return type
                if (preg_match('/@return\s+([^\s]+)\s*([^\n\*]+)/', $docComment, $matches)) {
                    $returns                                    = ['type' => $matches[1], 'description' => trim((string) $matches[2])];
                    $result['services'][$methodName]['returns'] = array_filter($returns);
                }
            }
        }

        return $result;
    }

    /**
     * Reset Local Class Vars after Execute.
     */
    private function resetVars()
    {
        $this->response = $this->calls = [];
        $this->hasCalls = $this->isBatchCall = false;
    }
}
