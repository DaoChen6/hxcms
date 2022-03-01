<?php


namespace app\model;


use think\db\exception\ModelNotFoundException;
use think\facade\App;
use think\facade\Db;
use think\Model;

class Photo extends Model
{
    public function chapter(){
        return $this->belongsTo('chapter');
    }

    public function getPhotos($order, $where, $num)
    {
        $img_domain = config('site.img_domain');
        if ($num == 0) {
            $photos = Db::name('photo')->where($where)->order($order)
                ->partition(['p0','p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])->select();
        } else {
            if (strpos($num, ',') !== false) {
                $arr = explode(',',$num);
                $photos = Db::name('photo')->where($where)->limit($arr[0],$arr[1])->order($order)
                    ->partition(['p0','p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])->select();
            } else {
                $photos = Db::name('photo')->where($where)->limit($num)->order($order)
                    ->partition(['p0','p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])->select();
            }
        }

        foreach ($photos['data'] as &$photo)
        {
            if (substr($photo['img_url'], 0, 4) === "http") {

            } else {
                $photo['img_url'] = $img_domain . $photo['img_url'];
            }
        }
        return $photos;
    }

    public function getPagedPhotos($order, $where, $pagesize)
    {
        $img_domain = config('site.img_domain');
        $data = Db::name('photo')->where($where)->order($order)
            ->partition(['p0','p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])
            ->paginate([
                'list_rows' => $pagesize,
                'query' => request()->param(),
            ]);

        $arr = $data->toArray();
        foreach ($arr['data'] as &$photo)
        {
            if (substr($photo['img_url'], 0, 4) === "http") {

            } else {
                $photo['img_url'] = $img_domain . $photo['img_url'];
            }
        }

        $paged = array();
        $paged['photos'] = $arr['data'];
        $paged['page'] = [
            'total' => $arr['total'],
            'per_page' => $arr['per_page'],
            'current_page' => $arr['current_page'],
            'last_page' => $arr['last_page'],
            'query' => request()->param()
        ];
        return $paged;
    }

    public function getLastPhoto($chapter_id)
    {
        return Db::name('photo')->where('chapter_id', '=', $chapter_id)->order('id', 'desc')
            ->limit(1)->partition(['p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])->find();
    }
}