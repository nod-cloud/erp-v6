<?php
namespace app\index\validate;
use think\Validate;
class Brand extends Validate{
    //默认创建规则
    protected $rule = [
        ['name', 'require|RepeatName:create', '品牌名称不可为空!|字段数据重复'],
        ['number', 'RepeatNumber:create', '字段数据重复'],
        ['more', 'array', '扩展信息格式不正确!']
    ];
    //场景规则
    protected $scene = [
        'update'  =>  [
            'name'=>'require|RepeatName:update',
            'number'=>'RepeatNumber:update',
            'more'
        ]
    ];
    //品牌名称重复性判断
    protected function RepeatName($val,$rule,$data){
        $sql['name']=$val;
        $rule=='update'&&($sql['id']=['neq',$data['id']]);
        $nod=db('brand')->where($sql)->find();
        return empty($nod)?true:'品牌名称[ '.$val.' ]已存在!';
    }
    //品牌编号重复性判断
    protected function RepeatNumber($val,$rule,$data){
        $sql['number']=$val;
        $rule=='update'&&($sql['id']=['neq',$data['id']]);
        $nod=db('brand')->where($sql)->find();
        return empty($nod)?true:'品牌编号[ '.$val.' ]已存在!';
    }
}