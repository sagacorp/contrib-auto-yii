<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Yii;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use yii\base\Application;
use yii\base\Controller;
use yii\base\InlineAction;
use yii\base\Response;

class YiiInstrumentation
{
    public const NAME = 'yii';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.yii',
            null,
            'https://opentelemetry.io/schemas/1.32.0'
        );

        hook(
            Application::class,
            'run',
            pre: static function (
                Application $application,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation) : void {
                $request = $application->getRequest();
                if ($request->getIsConsoleRequest()) {
                    $parent = Context::getCurrent();
                    $spanName = 'yii_cli_run';
                } else {
                    $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());
                    $spanName = 'yii_web_run';
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder($spanName)
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (
                Application $application,
                array $params,
                mixed $result,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();

                $span = Span::fromContext($scope->context());

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        hook(
            Application::class,
            'handleRequest',
            pre: static function (
                Application $application,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation) : void {
                $request = $application->getRequest();
                $parent = Context::getCurrent();

                $spanName = $request->getIsConsoleRequest() ? 'RUN' : $request->getMethod();

                /** @psalm-suppress ArgumentTypeCoercion */
                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder($spanName)
                    ->setParent($parent)
                    ->setSpanKind($request->getIsConsoleRequest() ? SpanKind::KIND_INTERNAL : SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                if (!$request->getIsConsoleRequest()) {
                    $spanBuilder->setAttribute(UrlAttributes::URL_FULL, $request->getAbsoluteUrl())
                        ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaders()->get('Content-Length', null, true))
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getIsSecureConnection() ? 'https' : 'http');
                }

                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (
                Application $application,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();

                $span = Span::fromContext($scope->context());

                if ($response instanceof \yii\web\Response) {
                    $statusCode = $response->getStatusCode();
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
                    $span->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $response->version);
                    $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, self::getResponseLength($response));

                    $headers = $response->getHeaders();
                    foreach ((array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                        if ($headers->has($header)) {
                            /** @psalm-suppress ArgumentTypeCoercion */
                            $span->setAttribute(
                                sprintf('%s.%s', HttpAttributes::HTTP_RESPONSE_HEADER, strtr(strtolower($header), ['-' => '_'])),
                                $headers->get($header, null, true)
                            );
                        }
                    }

                    if ($statusCode >= 400 && $statusCode < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    $prop = Globals::responsePropagator();
                    $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                }

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );

        hook(
            Controller::class,
            'runAction',
            post: static function (
                Controller $controller,
            ) : void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $span->updateName(($span instanceof ReadableSpanInterface ? $span->getName() . ' ' : '') . \Yii::$app->requestedRoute);

                $action = $controller->action;
                $span->setAttribute(HttpAttributes::HTTP_ROUTE, get_class($controller) . ($action instanceof InlineAction ? '::' . $action->actionMethod : ' - ' . get_class($action)));
            }
        );
    }

    protected static function getResponseLength(\yii\web\Response $response): ?string
    {
        $headerValue = $response->getHeaders()->get('Content-Length', null, true);
        if (is_string($headerValue)) {
            return $headerValue;
        }

        if ($response->content != null) {
            return (string) (strlen($response->content));
        }

        return null;
    }
}
