<?php

use App\Http\Middleware\SecurityHeaders;
use App\Services\Conversions\PipelineException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [AuthenticateSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api', 'api/*')) {
                return null;
            }
            if ($exception instanceof HttpResponseException) {
                return $exception->getResponse();
            }
            if ($exception instanceof PipelineException) {
                $message = match ($exception->errorCode) {
                    'TOOL_NOT_INCLUDED' => 'Cet outil n’est pas inclus dans votre formule actuelle.',
                    'BATCH_NOT_INCLUDED' => 'Le traitement par lots nécessite la formule Business.',
                    'NO_TEXT_FOUND' => 'Aucun texte lisible n’a été détecté dans cette image.',
                    'NO_SPEECH' => 'Aucune parole exploitable n’a été détectée dans ce fichier.',
                    'UNSAFE_SOURCE' => 'Cette adresse ou sa destination réseau ne sont pas autorisées.',
                    'SOURCE_INCOMPATIBLE' => 'Cette vidéo est absente, incompatible ou dépasse les limites autorisées.',
                    'LICENSE_NOT_ALLOWED' => 'Cette version accepte uniquement les vidéos Wikimedia Commons sous CC0 1.0.',
                    'REMOTE_UNAVAILABLE' => 'La vidéothèque est momentanément indisponible. Réessayez plus tard.',
                    'INVALID_MEDIA' => 'Ce fichier audio ou vidéo est invalide ou endommagé.',
                    'MEDIA_TOO_COMPLEX' => 'Ce média dépasse les limites de durée, de résolution ou de pistes.',
                    'UNSUPPORTED_MEDIA_CODEC' => 'Le codec de ce fichier n’est pas pris en charge.',
                    'PROTECTED_MEDIA' => 'Les médias chiffrés ou protégés ne sont pas pris en charge.',
                    'MISSING_MEDIA_STREAM' => 'La piste audio ou vidéo requise est absente.',
                    'INVALID_DOCUMENT' => 'Ce document est endommagé ou son contenu n’est pas compatible.',
                    'PROTECTED_DOCUMENT' => 'Les documents protégés ou chiffrés ne sont pas pris en charge.',
                    'DOCUMENT_TOO_COMPLEX' => 'Ce document dépasse les limites de pages, de cellules ou de décompression.',
                    'UNSAFE_DOCUMENT', 'EXTERNAL_REFERENCE' => 'Ce document contient des éléments actifs ou des références externes non autorisés.',
                    'INVALID_OPTIONS' => 'La plage de pages ou la feuille demandée n’existe pas.',
                    'OUTPUT_LIMIT_EXCEEDED' => 'Le résultat ou les fichiers temporaires dépassent la limite autorisée.',
                    'INVALID_IMAGE' => 'Cette image est endommagée ou invalide.',
                    'IMAGE_TOO_COMPLEX' => 'Cette image dépasse les limites de dimensions ou de pixels.',
                    'ANIMATED_IMAGE_UNSUPPORTED' => 'Les images animées ne sont pas prises en charge.',
                    'TOOL_UNAVAILABLE' => 'Cet outil est en préparation. Aucun fichier n’a été conservé.',
                    'FILE_TOO_LARGE' => 'Le fichier dépasse la taille autorisée.',
                    'QUOTA_EXCEEDED' => 'Votre quota ou votre limite de tâches simultanées est atteint.',
                    'STORAGE_QUOTA_EXCEEDED' => 'Votre limite de stockage temporaire est atteinte.',
                    'SERVER_BUSY' => 'Le serveur est occupé. Réessayez plus tard.',
                    'PIPELINE_UNAVAILABLE' => 'Le traitement est momentanément indisponible.',
                    'RESULT_UNAVAILABLE' => 'Ce résultat est indisponible ou a expiré.',
                    default => 'Le fichier ne peut pas être traité.',
                };

                return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $message]], $exception->httpStatus,
                    in_array($exception->httpStatus, [429, 503], true) ? ['Retry-After' => '60'] : []);
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            [$code, $message] = match ($status) {
                400 => ['INVALID_REQUEST', 'La requête est invalide.'],
                401 => ['AUTHENTICATION_REQUIRED', 'Veuillez vous connecter.'],
                403 => ['FORBIDDEN', 'Cette action n’est pas autorisée.'],
                404 => ['NOT_FOUND', 'Cette ressource est introuvable.'],
                405 => ['METHOD_NOT_ALLOWED', 'Cette méthode n’est pas autorisée.'],
                413 => ['FILE_TOO_LARGE', 'Le fichier dépasse la taille autorisée.'],
                419 => ['SESSION_EXPIRED', 'Votre session a expiré. Rechargez la page.'],
                422 => ['VALIDATION_FAILED', 'Vérifiez les informations saisies.'],
                429 => ['RATE_LIMITED', 'Trop de requêtes. Réessayez dans un instant.'],
                503 => ['SERVICE_UNAVAILABLE', 'Le service est momentanément indisponible.'],
                default => ['INTERNAL_ERROR', 'Une erreur est survenue. Veuillez réessayer.'],
            };

            $error = ['code' => $code, 'message' => $message];
            if ($exception instanceof ValidationException) {
                $error['fields'] = $exception->errors();
            }

            return response()->json(['error' => $error], $status,
                $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
        });
    })->create();
