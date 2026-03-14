<?php
namespace App\Http\Controllers;

use App\Services\VideoManager\Contracts\FavoriteServiceInterface;
use Illuminate\Http\Request;

class FavController extends Controller
{
    public function __construct(public FavoriteServiceInterface $favoriteService)
    {

    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = $this->favoriteService->getUnifiedContentList();
        if ($data) {
            return response()->json($data);
        } else {
            return response()->json([]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $id      = intval($id);
        $content = $this->favoriteService->getUnifiedContentDetail($id);

        if ($content) {
            // 确保视频关联已加载
            if (isset($content->videos) && method_exists($content->videos, 'load')) {
                $content->videos->load('parts');
            }
            return response()->json($content);
        } else {
            return response()->json([]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    // 新增排序保存接口 (支持混合排序)
    public function reorder(\Illuminate\Http\Request $request)
    {
        $ids = $request->input('ids'); 
        if (is_array($ids)) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
                foreach ($ids as $index => $id) {
                    if ($id > 0) {
                        // 正数是收藏夹
                        \App\Models\FavoriteList::where('id', $id)->update(['sort_order' => $index]);
                    } elseif ($id < 0) {
                        // 负数是订阅夹，记得用 abs() 转为绝对值
                        \App\Models\Subscription::where('id', abs($id))->update(['sort_order' => $index]);
                    }
                }
            });
        }
        return response()->json(['code' => 0, 'message' => 'success']);
    }
}
