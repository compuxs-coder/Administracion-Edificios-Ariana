<?php

namespace Src\Comunicacion\Domain\Contracts;

use Illuminate\Http\UploadedFile;
use Src\Edificio\Domain\Enums\PermisoEdificio;

interface ComunicacionRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $buildingId, string $messageId, int $deliveryPage = 1, int $omittedPage = 1): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $buildingId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $buildingId, string $messageId, array $data): void;

    /** @return array<string, int> */
    public function previewRecipients(string $userId, string $buildingId, string $messageId): array;

    /** @return array{id: string} */
    public function publish(string $userId, string $buildingId, string $messageId, string $expectedUpdatedAt): array;

    public function archive(string $userId, string $buildingId, string $messageId): void;

    /** @return array{id: string} */
    public function storeDocument(string $userId, string $buildingId, string $messageId, UploadedFile $file, ?string $description): array;

    public function deleteDocument(string $userId, string $buildingId, string $messageId, string $documentId): void;

    /** @return array{contents: string, nombre: string, mimeType: string} */
    public function documentDownload(string $userId, string $buildingId, string $messageId, string $documentId): array;

    /** @return array{contents: string, nombre: string, mimeType: string} */
    public function signedDocumentDownload(string $deliveryId, string $documentId): array;

    public function retryDelivery(string $userId, string $buildingId, string $messageId, string $deliveryId): void;

    /** @return list<string> */
    public function dueDeliveryIds(?string $messageId = null, int $limit = 500): array;

    /** @return array<string, mixed>|null */
    public function claimDelivery(string $deliveryId): ?array;

    public function markDeliverySent(string $deliveryId, int $attempt, ?string $providerMessageId = null): void;

    public function markDeliveryFailure(string $deliveryId, int $attempt, \Throwable $exception): void;
}
