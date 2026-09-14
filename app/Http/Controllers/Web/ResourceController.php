<?php

namespace App\Http\Controllers\Web;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResourceController extends Controller
{
    public function index(Request $request, string $slug): View
    {
        $config = config("mims_web.resources.{$slug}");
        abort_if(! $config, 404);

        $this->authorizePermission($config['permission']);

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $search = trim((string) $request->query('q', ''));
        $query = $model::query()->latest();

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $config) {
                foreach (array_keys($config['columns']) as $field) {
                    $builder->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        $rows = $query->paginate(25)->withQueryString();

        return view('resource.index', [
            'title' => $config['title'],
            'slug' => $slug,
            'columns' => $config['columns'],
            'rows' => $rows,
            'search' => $search,
        ]);
    }

    public function show(string $slug, int $id): View
    {
        $config = config("mims_web.resources.{$slug}");
        abort_if(! $config, 404);

        $this->authorizePermission($config['permission']);

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $record = $model::query()->findOrFail($id);

        return view('resource.show', [
            'title' => $config['title'],
            'slug' => $slug,
            'record' => $record,
        ]);
    }
}
