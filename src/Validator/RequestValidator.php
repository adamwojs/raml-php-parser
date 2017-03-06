<?php
namespace Raml\Validator;

use Exception;
use Negotiation\Negotiator;
use Psr\Http\Message\RequestInterface;
use Raml\Exception\InvalidJsonException;
use Raml\Exception\InvalidSchemaException;
use Raml\Exception\ValidationException;
use Raml\NamedParameter;

class RequestValidator
{
    /**
     * @var ValidatorSchemaHelper
     */
    private $schemaHelper;

    /**
     * @var Negotiator
     */
    private $negotiator;

    /**
     * @param ValidatorSchemaHelper $schema
     * @param Negotiator $negotiator
     */
    public function __construct(ValidatorSchemaHelper $schema, Negotiator $negotiator)
    {
        $this->schemaHelper = $schema;
        $this->negotiator = $negotiator;
    }

    /**
     * @param RequestInterface $request
     * @throws Exception
     */
    public function validateRequest(RequestInterface $request)
    {
        $this->assertMediaTypes($request);
        $this->assertNoMissingParameters($request);
        $this->assertValidParameters($request);

        if (strtolower($request->getMethod()) !== 'get') {
            $this->assertValidBody($request);
        }
    }

    /**
     * @param RequestInterface $request
     * @throws ValidatorRequestException
     */
    private function assertNoMissingParameters(RequestInterface $request)
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $schemaParameters = $this->schemaHelper->getQueryParameters($method, $path, true);
        $requestParameters = $this->getRequestParameters($request);

        $missingParameters = array_diff_key($schemaParameters, $requestParameters);
        if (count($missingParameters) === 0) {
            return;
        }

        throw new ValidatorRequestException(sprintf(
            'Missing request parameters required by the schema for `%s %s`: %s',
            strtoupper($method),
            $path,
            join(', ', array_keys($missingParameters))
        ));
    }

    /**
     * @param RequestInterface $request
     * @throws ValidatorRequestException
     */
    private function assertValidParameters(RequestInterface $request)
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $schemaParameters = $this->schemaHelper->getQueryParameters($method, $path);
        $requestParameters = $this->getRequestParameters($request);

        /** @var NamedParameter $schemaParameter */
        foreach ($schemaParameters as $schemaParameter) {
            $key = $schemaParameter->getKey();

            if (!array_key_exists($key, $requestParameters)) {
                continue;
            }

            try {
                $schemaParameter->validate($requestParameters[$key]);
            } catch (ValidationException $exception) {
                $message = sprintf(
                    'Request parameter does not match schema for `%s %s`: %s',
                    strtoupper($method),
                    $path,
                    $exception->getMessage()
                );

                throw new ValidatorRequestException($message, 0, $exception);
            }
        }
    }

    /**
     * @param RequestInterface $request
     */
    private function assertValidBody(RequestInterface $request)
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $contentType = $request->getHeaderLine('Content-Type');

        $schemaBody = $this->schemaHelper->getRequestBody($method, $path, $contentType);

        $body = json_decode($request->getBody()->getContents(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidJsonException(json_last_error_msg());
        }
        try {
            $schemaBody->getValidator()->validate($body);
        } catch (InvalidSchemaException $exception) {
            $message = sprintf(
                'Request body for %s %s with content type %s does not match schema: %s',
                strtoupper($method),
                $path,
                $contentType,
                $this->getSchemaErrorsAsString($exception->getErrors())
            );

            throw new ValidatorRequestException($message, 0, $exception);
        }
    }

    /**
     * @param RequestInterface $request
     * @return array
     */
    private function getRequestParameters(RequestInterface $request)
    {
        parse_str($request->getUri()->getQuery(), $requestParameters);

        return $requestParameters;
    }

    /**
     * @param array $errors
     * @return string
     */
    private function getSchemaErrorsAsString(array $errors)
    {
        return join(', ', array_map(function (array $error) {
            return sprintf('%s (%s)', $error['property'], $error['constraint']);
        }, $errors));
    }

    private function assertMediaTypes(RequestInterface $request)
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $responseSchemas = $this->schemaHelper->getResponses(
            $method,
            $path
        );

        $priorities = [];
        foreach ($responseSchemas as $responseSchema) {
            $priorities = array_merge($priorities, $responseSchema->getTypes());
        }

        if (!$priorities) {
            $priorities = $this->schemaHelper->getDefaultMediaTypes();
        }

        if (!$priorities) {
            return;
        }

        $acceptHeader = $request->getHeaderLine('Accept');
        $accept = $acceptHeader ? $this->negotiator->getBest($acceptHeader, $priorities) : null;

        if ($accept === null) {
            throw new ValidatorRequestException('Invalid Media type');
        }
    }
}
