<?php

namespace App\Controllers\Admin\Videos;

use App\Models\File;
use App\Models\Video;
use App\Services\TempStorage;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;
use Vesp\Controllers\Controller;
use Vesp\Services\Eloquent;

class Upload extends Controller
{
    protected TempStorage $storage;

    public const TUS_PROTOCOL_VERSION = '1.0.0';
    public const TUS_EXTENSIONS = ['creation', 'termination', 'expiration'];
    protected const EXPIRE_HOURS = 24;

    public function __construct(Eloquent $eloquent, TempStorage $storage)
    {
        parent::__construct($eloquent);
        $this->storage = $storage;
    }

    public function options(): ResponseInterface
    {
        return parent::options()
            ->withHeader('Allow', 'OPTIONS,HEAD,POST,PATCH,DELETE');
    }

    public function head(): ResponseInterface
    {
        if (!$meta = $this->storage->getMeta($this->getProperty('uuid', ''))) {
            return $this->failure('', 404);
        }

        return $this->success()
            ->withHeader('Upload-Length', $meta['size'])
            ->withHeader('Upload-Offset', $meta['offset'])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function post(): ResponseInterface
    {
        $meta = [];
        if ($headers = explode(',', $this->request->getHeaderLine('Upload-Metadata'))) {
            foreach ($headers as $header) {
                $pieces = explode(' ', trim($header));
                $meta[$pieces[0]] = !empty($pieces[1]) ? base64_decode($pieces[1]) : '';
            }
        }
        if (empty($meta['filename'])) {
            return $this->failure('', 400);
        }
        $tmp = explode('.', $meta['filename']);
        $ext = count($tmp) > 1 ? end($tmp) : 'mp4';

        if (!$size = (int)$this->request->getHeaderLine('Upload-Length')) {
            return $this->failure('', 400);
        }

        $uuid = Uuid::uuid4()->toString();
        $meta['file'] = $uuid . '/video.' . $ext;
        $meta['offset'] = 0;
        $meta['size'] = $size;
        $meta['expires'] = Carbon::now()->addHours($this::EXPIRE_HOURS)->toRfc7231String();

        $this->storage->getBaseFilesystem()->write($meta['file'], '');
        $this->storage->setMeta($uuid, $meta);
        $location = rtrim((string)$this->request->getUri(), '/') . '/' . $uuid;
        $location = str_replace('http://', '//', $location);

        return $this->success('', 201)
            ->withHeader('Location', $location)
            ->withHeader('Upload-Expires', $meta['expires']);
    }

    public function patch(): ResponseInterface
    {
        $uuid = $this->getProperty('uuid');
        if (!$uuid || !$meta = $this->storage->getMeta($uuid)) {
            return $this->failure('', 410);
        }

        $offset = (int)$this->request->getHeaderLine('Upload-Offset');
        if ($offset && $offset !== $meta['offset']) {
            return $this->failure('', 409);
        }

        $contentType = $this->request->getHeaderLine('Content-Type');
        if ($contentType !== 'application/offset+octet-stream') {
            return $this->failure('', 415);
        }

        if (!$meta = $this->storage->writeFile($uuid)) {
            return $this->failure('', 500);
        }

        if ($meta['size'] === $meta['offset']) {
            $this->finishUpload($uuid);
        }

        return $this->success('', 204)
            ->withHeader('Content-Type', 'application/offset+octet-stream')
            ->withHeader('Upload-Expires', $meta['expires'])
            ->withHeader('Upload-Offset', $meta['offset']);
    }

    public function delete(): ResponseInterface
    {
        $uuid = $this->getProperty('uuid');
        if (!$uuid || !$this->storage->getMeta($uuid)) {
            return $this->failure('', 404);
        }

        if (Video::query()->find($uuid)) {
            return $this->failure('', 403);
        }

        $this->storage->deleteFile($uuid);

        return $this->success('', 204);
    }

    protected function response($data, int $status = 200, string $reason = ''): ResponseInterface
    {
        return parent::response($data, $status, $reason)
            ->withHeader('Tus-Extension', implode(',', $this::TUS_EXTENSIONS))
            ->withHeader('Tus-Resumable', $this::TUS_PROTOCOL_VERSION);
    }

    protected function finishUpload(string $uuid): void
    {
        $meta = $this->storage->getMeta($uuid);
        $tmp = explode('.', $meta['filename']);
        array_pop($tmp);

        $video = new Video();
        $video->id = $uuid;
        $video->title = implode('.', $tmp);
        $video->file_id = $this->storage::getFakeFile($meta['filename'], $meta['filetype'], $meta['size'])->id;
        $video->save();
    }
}