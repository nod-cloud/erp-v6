<?php
namespace app\index\controller;
use think\Hook;
use app\index\controller\Acl;
use app\index\model\Eftclass;
use app\index\model\Eftinfo;
use app\index\model\Account;
use app\index\model\Accountinfo;
class Eft extends Acl {
    //资金调拨模块
    //---------------(^_^)---------------//
    //主视图
    public function main(){
        return $this->fetch();
    }
    //新增|更新信息
    public function set(){
        $input=input('post.');
        if(isset($input['id'])){
            //验证资金调拨单详情
            if(isset_full($input,'tab')){
                foreach ($input['tab'] as $tab_key=>$tab_vo) {
                    $tab_vali = $this->validate($tab_vo,'Eftinfo');//详情验证
                    if($tab_vali!==true){
                        return json(['state'=>'error','info'=>'[ 数据表格 ]第'.($tab_key+1).'行'.$tab_vali]);
                        exit;
                    }
                }
            }else{
                return json(['state'=>'error','info'=>'数据表格不可为空!']);
                exit;
            }
            //验证操作类型
            if(empty($input['id'])){
                //新增
                $input['merchant']=Session('is_merchant_id');//补充商户信息
                $vali = $this->validate($input,'Eftclass');
                if($vali===true){
                    $create_info=Eftclass::create(syn_sql($input,'eftclass'));
                    Hook::listen('create_eft',$create_info);//资金调拨单新增行为
                    push_log('新增资金调拨单[ '.$create_info['number'].' ]');//日志
                    $resule=['state'=>'success'];
                }else{
                    $resule=['state'=>'error','info'=>$vali];
                }
            }else{
                //更新
                $vali = $this->validate($input,'Eftclass.update');
                if($vali===true){
                    $update_info=Eftclass::update(syn_sql($input,'eftclass'));
                    Hook::listen('update_eft',$update_info);//资金调拨单更新行为
                    push_log('更新资金调拨单[ '.$update_info['number'].' ]');//日志
                    Eftinfo::where(['pid'=>$update_info['id']])->delete();
                    $resule=['state'=>'success'];
                }else{
                    $resule=['state'=>'error','info'=>$vali];
                }
            }
            //添加资金调拨单详情
            if($resule['state']=='success'){
                $info_pid=empty($input['id'])?$create_info['id']:$update_info['id'];
                foreach ($input['tab'] as $info_vo) {
                    $info_vo['pid']=$info_pid;
                    Eftinfo::create(syn_sql($info_vo,'eftinfo'));
                }
            }
        }else{
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }
        //兼容自动审核[新增操作]
        if($resule['state']=='success'&&empty($input['id'])){
            empty(get_sys(['auto_auditing']))||($this->auditing([$create_info['id']],true));
        }
        return json($resule);
    }
    //报表视图
    public function form(){
        return $this->fetch();
    }
    //报表列表
    public function form_list(){
        $input=input('post.');
        //数据完整性判断
        if(isset_full($input,'page') && isset_full($input,'limit')){
            $sql=get_sql($input,[
                'number'=>'full_like',
                'start_time'=>'stime',
                'end_time'=>'etime',
                'type'=>'full_dec_1',
                'user'=>'full_division_in',
                'data'=>'full_like',
            ],'eftclass');//构造SQL
            //处理调出账户搜索
            if(isset_full($input,'account')){
                $info=get_db_field('eftinfo',['account'=>['in',explode(",",$input['account'])]],'pid');//取出详情表数据
                sql_assign($sql,'id',$info,'intersect');//多表查询赋值处理
            }
            //处理调入账户搜索
            if(isset_full($input,'toaccount')){
                $info=get_db_field('eftinfo',['toaccount'=>['in',explode(",",$input['toaccount'])]],'pid');//取出详情表数据
                sql_assign($sql,'id',$info,'intersect');//多表查询赋值处理
            }
            $sql['merchant']=['in',get_auth_merchant()];//补全授权商户数据
            $sql=auth('eftclass',$sql);//数据鉴权
            $count = Eftclass::where ($sql)->count();//获取总条数
            $arr = Eftclass::with('merchantinfo,userinfo')->where($sql)->page($input['page'],$input['limit'])->order('id desc')->select();//查询分页数据
            $resule=[
                'code'=>0,
                'msg'=>'获取成功',
                'count'=>$count,
                'data'=>$arr
            ];//返回数据
        }else{
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }
        return json($resule);
    }
    //详情
    public function info(){
        $input=input('get.');
        //数据完整性判断
        if(isset_full($input,'id')){
            $class=Eftclass::where(['id'=>$input['id']])->find();
            $info=Eftinfo::with('accountinfo,toaccountinfo')->where(['pid'=>$input['id']])->select()->toarray();
            $this->assign('class',$class);
            $this->assign('info',$info);
            return $this->fetch('main');
        }else{
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }
        return json($resule);
    }
    //审核
    public function auditing($arr=[],$auto=false){
        (empty($arr))&&($arr=input('post.arr'));//兼容多态审核
        if(empty($arr)){
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }else{
            $class_data=[];//初始化CLASS数据
            $info_data=[];//初始化INFO数据
            //转存数据
            foreach ($arr as $arr_vo) {
                $class=Eftclass::where(['id'=>$arr_vo])->find();
                $info=Eftinfo::where(['pid'=>$arr_vo])->select();
                $class_data[$arr_vo]=$class;//转存CLASS数据
                $info_data[$arr_vo]=$info;//转存INFO数据
            }
            //实际操作
            foreach ($arr as $arr_vo) {
                $class=$class_data[$arr_vo];//读取CLASS数据
                $info=$info_data[$arr_vo];//读取INFO数据
                //判断操作类型
                if(empty($class['type']['nod'])){
                    //审核操作
                    foreach ($info as $info_vo) {
                        //1.处理调出
                        Account::where (['id'=>$info_vo['account']])->setDec('balance',$info_vo['total']);//操作资金账户[-]
                        Accountinfo::create (['pid'=>$info_vo['account'],'set'=>0,'money'=>$info_vo['total'],'type'=>13,'time'=>time(),'user'=>Session('is_user_id'),'class'=>$arr_vo]);//新增资金详情
                        //2.处理调入
                        Account::where (['id'=>$info_vo['toaccount']])->setInc('balance',$info_vo['total']);//操作资金账户[+]
                        Accountinfo::create (['pid'=>$info_vo['account'],'set'=>1,'money'=>$info_vo['total'],'type'=>14,'time'=>time(),'user'=>Session('is_user_id'),'class'=>$arr_vo]);//新增资金详情
                    }
                    Eftclass::update(['id'=>$arr_vo,'type'=>1,'auditinguser'=>Session('is_user_id'),'auditingtime'=>time()]);//更新CLASS数据
                    push_log(($auto?'自动':'').'审核资金调拨单[ '.$class['number'].' ]');
                }else{
                    //反审核操作
                    foreach ($info as $info_vo){
                        //1.处理调出
                        Account::where (['id'=>$info_vo['account']])->setInc('balance',$info_vo['total']);//操作资金账户[+]
                        //2.处理调入
                        Account::where (['id'=>$info_vo['toaccount']])->setDec('balance',$info_vo['total']);//操作资金账户[-]
                    }
                    Accountinfo::where(['type'=>['in',[13,14]],'class'=>$arr_vo])->delete();//删除资金账户详情
                    Eftclass::update(['id'=>$arr_vo,'type'=>0,'auditinguser'=>0,'auditingtime'=>0]);//更新CLASS数据
                    push_log ('反审核资金调拨单[ '.$class['number'].' ]');
                }
            }
            $resule=['state'=>'success'];
        }
        return $auto?true:json($resule);
    }
    //删除信息
    public function del(){
        $input=input('post.');
        if(isset_full($input,'arr') && is_array($input['arr'])){
            $class=db('eftclass')->where(['id'=>['in',$input['arr']]])->select()->ToArray();//获取CLASS数据
            $data = searchdata($class,['type'=>['eq',1]]);//查询已审核单据
            //数据检验
            if(empty($data)){
                foreach ($class as $class_vo) {
                    push_log('删除资金调拨单[ '.$class_vo['number'].' ]');//日志
                    Hook::listen('del_eft',$class_vo['id']);//资金调拨单删除行为
                }
                Eftclass::where(['id'=>['in',$input['arr']]])->delete();
                Eftinfo::where(['pid'=>['in',$input['arr']]])->delete();
                $resule=['state'=>'success'];
            }else{
                $resule=['state'=>'error','info'=>'资金调拨单[ '.$data[0]['number'].' ]已审核,不可删除!'];
            }
        }else{
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }
        return json($resule);
    }
    //导出报表信息
    public function exports(){
        $input=input('get.');
        if(isset($input['mode'])){
            push_log('导出资金调拨单数据');//日志
            $sql=get_sql($input,[
                'number'=>'full_like',
                'start_time'=>'stime',
                'end_time'=>'etime',
                'type'=>'full_dec_1',
                'user'=>'full_division_in',
                'data'=>'full_like',
            ],'eftclass');//构造SQL
            //处理调出账户搜索
            if(isset_full($input,'account')){
                $info=get_db_field('eftinfo',['account'=>['in',explode(",",$input['account'])]],'pid');//取出详情表数据
                sql_assign($sql,'id',$info,'intersect');//多表查询赋值处理
            }
            //处理调入账户搜索
            if(isset_full($input,'toaccount')){
                $info=get_db_field('eftinfo',['toaccount'=>['in',explode(",",$input['toaccount'])]],'pid');//取出详情表数据
                sql_assign($sql,'id',$info,'intersect');//多表查询赋值处理
            }
            $sql['merchant']=['in',get_auth_merchant()];//补全授权商户数据
            $sql=auth('eftclass',$sql);//数据鉴权
            $arr = Eftclass::with('merchantinfo,userinfo')->where($sql)->order('id desc')->select();//查询数据
            //判断报表类型
            if(empty($input['mode'])){
                //简易报表
                $formfield=get_formfield('eft_export','array');//获取字段配置
                //开始构造导出数据
                $excel=[];//初始化导出数据
                //1.填充标题数据
                array_push($excel,['type'=>'title','info'=>'资金调拨单列表']);
                //2.构造表格数据
                $table_cell=[];//初始化表头数据
                //构造表头数据
                foreach ($formfield as $formfield_vo) {
                    $table_cell[$formfield_vo['key']]=$formfield_vo['text'];
                }
                $table_data=[];//初始化表内数据
                //构造表内数据
                foreach ($arr as $arr_vo) {
                    $row_data=[];
                    //循环字段配置
                    foreach ($formfield as $formfield_vo) {
                        $val='nod_initial';//初始化数据
                        //循环匹配数据源
                        foreach (explode('|',$formfield_vo['data']) as $source) {
                            $val=$val=='nod_initial'?$arr_vo[$source]:(isset($val[$source])?$val[$source]:'');
                        }
                        $row_data[$formfield_vo['key']]=$val;//数据赋值
                    }
                    array_push($table_data,$row_data);//加入行数据
                }
                array_push($excel,['type'=>'table','info'=>['cell'=>$table_cell,'data'=>$table_data]]);//填充表内数据
                //3.导出execl
                export_excel('资金调拨单列表',$excel);
            }else{
                //详细报表
                $files=[];//初始化文件列表
                $formfield=get_formfield('eft_exports','array');//获取字段配置
                //循环CLASS数据
                foreach ($arr as $arr_vo) {
                    $excel=[];//初始化导出数据
                    //1.填充标题数据
                    array_push($excel,['type'=>'title','info'=>'资金调拨单']);
                    //2.添加基础字段
                    array_push($excel,['type'=>'node','info'=>[
                        '单据日期:'.$arr_vo['time'],
                        '',
                        '单据编号:'.$arr_vo['number'],
                    ]]);
                    //3.构造表格数据
                    $info=Eftinfo::where(['pid'=>$arr_vo['id']])->select();
                    $table_cell=[];//初始化表头数据
                    //构造表头数据
                    foreach ($formfield as $formfield_vo) {
                        $table_cell[$formfield_vo['key']]=$formfield_vo['text'];
                    }
                    $table_data=[];//初始化表内数据
                    //构造表内数据
                    foreach ($info as $info_vo) {
                        $row_data=[];
                        //循环字段配置
                        foreach ($formfield as $formfield_vo) {
                            $val='nod_initial';//初始化数据
                            //循环匹配数据源
                            foreach (explode('|',$formfield_vo['data']) as $source) {
                                $val=$val=='nod_initial'?$info_vo[$source]:(isset($val[$source])?$val[$source]:'');
                            }
                            $row_data[$formfield_vo['key']]=$val;//数据赋值
                        }
                        array_push($table_data,$row_data);//加入行数据
                    }
                    array_push($excel,['type'=>'table','info'=>['cell'=>$table_cell,'data'=>$table_data]]);//填充表内数据
                    //4.添加基础字段
                    array_push($excel,['type'=>'node','info'=>[
                        '制单人:'.$arr_vo['userinfo']['name'],
                        '',
                        '备注信息:'.$arr_vo['data'],
                    ]]);
                    $path=export_excel($arr_vo['number'],$excel,false);//生成文件
                    array_push($files,$path);//添加文件路径数据
                }
                file_to_zip('资金调拨单明细',$files);//打包输出数据
            }
        }else{
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }
        return json($resule);
    }
    //打印
    public function prints(){
        $input=input('get.');
        if(isset_full($input,'id')){
            $print_name='eft';//模板标识
            $class=Eftclass::where(['id'=>$input['id']])->find();
            $info=Eftinfo::where(['pid'=>$input['id']])->select();
            $print_paper=get_sys(['print_paper']);
            //1.获取字段信息
            $formfield=get_formfield('eft_print','array');//获取字段配置
            //2.构造表格数据
            $tab_html=get_print_tab($formfield,$info);
            //3.获取模板代码
            $print=get_print($print_name);
            $print_text=$print[empty($print_paper)?'paper4':'paper2'];
            //4.赋值数据
            $this->assign('class',$class);
            $this->assign('tab_html',$tab_html);
            $this->assign('print_name',$print_name);
            $this->assign('paper_type',$print_paper);
            $this->assign('print_text',$print_text);
            return $this->fetch();
        }else{
            $resule=['state'=>'error','info'=>'传入参数不完整!'];
        }
        return json($resule);
    }
}