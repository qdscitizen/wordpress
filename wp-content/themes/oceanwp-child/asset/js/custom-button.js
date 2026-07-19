// jQuery 版本 —— 最简单、最稳定
jQuery(document).ready(function($) {
    // 监听 ID 为 myBtn1 的按钮点击
    $('#btn').click(function(e) {
        e.preventDefault(); // 阻止默认跳转
        
        // 你要执行的 JS 逻辑
        alert('jQuery 版本按钮点击成功！');
        
        // 示例：跳转页面
        // window.location.href = '/product';
    });
});