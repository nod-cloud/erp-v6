$('#userpage').selectpage({
    url:'/index/service/user_list',
    tip:'全部制单人',
    valid:'s|user'
});
layui.use(['table','laydate'], function() {
    var table=layui.table;
    var laydate=layui.laydate;
    table.render({
        id: 'data_table',
        elem: '#data_table',
        height:'full-120',
        even: true,
        cols:  [formfield],
        url: '/index/account/accountinfo_list',
        page: true,
        limit: 30,
        limits: [30,60,90,150,300],
        method: 'post',
        where: search_info('obj'),
    });//渲染表格
    //时间组件
    laydate.render({
        elem: '#s\\|start_time'
    });
    laydate.render({
        elem: '#s\\|end_time'
    });
});
//条件搜索
function search() {
    layui.use('table', function() {
        layui.table.reload('data_table',{
            where: search_info('obj'),
            page:1
        });
    });
}
//导出操作
function exports(){
    var info=search_info('url');
    jump_info('【 数据请求中 】',"/index/account/export_accountinfo?"+info,true);
}