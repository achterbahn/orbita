<?php

namespace App\Controllers\Web;

use App\Models\Topic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vesp\Controllers\ModelGetController;

class Topics extends ModelGetController
{
    protected string $model = Topic::class;
    protected string|array $primaryKey = ['uuid'];

    protected function beforeGet(Builder $c): Builder
    {
        $c->where('active', true);
        if ($this->user) {
            $c->with('views', function (HasMany $c) {
                $c->where('user_id', $this->user->id);
            });
        }

        return $c;
    }

    protected function beforeCount(Builder $c): Builder
    {
        $c->where('active', true);

        return $c;
    }

    protected function afterCount(Builder $c): Builder
    {
        $c->with('cover:id,uuid,updated_at');
        if ($this->user) {
            $c->with('views', function (HasMany $c) {
                $c->where('user_id', $this->user->id);
            });
        }

        return $c;
    }

    protected function addSorting(Builder $c): Builder
    {
        $c->orderByDesc('published_at');

        return $c;
    }

    public function prepareRow(Model $object): array
    {
        /** @var Topic $object */
        return $object->prepareOutput($this->user, !$this->getPrimaryKey());
    }
}