<?php

namespace App\Services\VideoManager\Contracts;

use App\Models\Video;
use App\Models\VideoPart;
use Illuminate\Database\Eloquent\Collection;

interface VideoServiceInterface
{
    public function getVideoInfo(string $id, bool $withParts = false): ?Video;

    public function getVideos(array $conditions = []): Collection;

    public function getVideosByPage(array $conditions = [], $page = 1, $perPage = 10): array;

    public function getAllPartsVideoForUser(Video $video): array;

    public function getVideosStat(array $conditions = []): array;

    public function getVideoPartFileSize(VideoPart $videoPart): int;

    public function count(): int;

    public function deleteVideos(array $ids, array $options = []): array;

    public function getVideosCache(): array;

    public function updateVideosCache(?array $videos = null): void;

    /** 批F·P0：按偏移读取缓存切片（只解码命中分块，内存恒定） */
    public function getVideosCacheSlice(int $offset, int $limit): array;

    /** 批F·P0：缓存总条数（分块模式精确值） */
    public function getVideosCacheTotal(): int;

    public function getFavVideosLightweight(int $favId): array;

    public function getSubVideosLightweight(int $subId): array;
}
