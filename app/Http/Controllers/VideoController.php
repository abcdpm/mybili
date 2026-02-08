<?php
namespace App\Http\Controllers;

use App\Services\DanmakuConverterService;
use App\Services\VideoManager\Contracts\DanmakuServiceInterface;
use App\Services\VideoManager\Contracts\FavoriteServiceInterface;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Illuminate\Http\Request;
use App\Models\Comment;

class VideoController extends Controller
{

    public function __construct(
        public VideoServiceInterface $videoService,
        public FavoriteServiceInterface $favoriteService,
        public DanmakuServiceInterface $danmakuService,
        public DanmakuConverterService $danmakuConverterService
    ) {

    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'query'      => 'nullable|string',
            'page'       => 'nullable|integer|min:1',
            'status'     => 'nullable|string',
            'downloaded' => 'nullable|string',
            'multi_part' => 'nullable|string',
            'fav_id'     => 'nullable|integer',
            'page_size'  => 'nullable|integer|min:1',
        ]);
        $page    = $data['page'] ?? 1;
        $perPage = 30;
        $result  = $this->videoService->getVideosByPage([
            'query'      => $data['query'] ?? '',
            'status'     => $data['status'] ?? '',
            'downloaded' => $data['downloaded'] ?? '',
            'multi_part' => $data['multi_part'] ?? '',
            'fav_id'     => $data['fav_id'] ?? '',
        ], $page, intval($data['page_size'] ?? $perPage));
        return response()->json([
            'stat'  => $result['stat'],
            'list'  => $result['list'],
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        if (config('services.bilibili.setting_read_only')) {
            abort(403);
        }
        // 补充其他ID
        $extend_ids = $request->input('extend_ids');
        if ($extend_ids && is_array($extend_ids)) {
            $ids = array_merge([$id], $extend_ids);
        } else {
            $ids = [$id];
        }
        $ids        = array_map('intval', $ids);
        $deletedIds = $this->videoService->deleteVideos($ids);
        if ($deletedIds) {
            return response()->json([
                'code'        => 0,
                'message'     => 'Video deleted successfully',
                'deleted_ids' => $deletedIds,
            ]);
        } else {
            return response()->json([
                'code'    => 1,
                'message' => 'Video deletion failed',
            ]);
        }
    }

    public function show(Request $request, int $id)
    {
        $video = $this->videoService->getVideoInfo($id, true);
        if ($video) {
            $video->video_parts   = $this->videoService->getAllPartsVideoForUser($video);
            $video->danmaku_count = $this->danmakuService->getVideoDanmakuCount($video);
            $video->load('favorite');
            $video->load('subscriptions');
            $video->load('upper');

            return response()->json($video);
        }
        abort(404);
    }

    public function progress()
    {
        $list = $this->videoService->getVideosCache();
        $data = [
            'data' => $list,
            'stat' => $this->videoService->getVideosStat([]),
        ];
        return response()->json($data, 200, []);
    }

    /**
     * 获取指定 CID 的弹幕数据（新格式）
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function danmaku(Request $request)
    {
        $cid = $request->input('id');
        
        if (!$cid) {
            return response()->json([
                'code'    => 1,
                'message' => 'CID 参数不能为空',
                'data'    => [],
            ]);
        }

        // 获取原始弹幕数据
        $danmakuList = $this->danmakuService->getDanmaku($cid);
        
        // 转换为新格式
        $convertedData = $this->danmakuConverterService->convert($danmakuList);
        
        return response()->json([
            'code' => 0,
            'data' => $convertedData,
        ]);
    }

    // 获取评论的接口
    public function comments($id)
    {
        $comments = \App\Models\Comment::where('video_id', $id)
            ->where('root', 0) // 只查主评论
            ->with(['replies' => function($query) {
                $query->orderBy('like', 'desc'); 
            }])
            // 【关键修改】优先按 is_top 倒序 (true=1 在前)，然后按点赞倒序
            ->orderBy('is_top', 'desc')
            ->orderBy('like', 'desc')
            // ->limit(50) // [修改] 去掉限制
            ->get();

        return response()->json($comments);
    }
}
