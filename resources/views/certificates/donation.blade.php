<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<style>
    /* 中文字体来自 storage/fonts（dompdf 默认字体目录），运行时登记、子集嵌入 */
    @page { margin: 0; }
    body {
        font-family: 'simhei', sans-serif;
        width: 595px;
        height: 842px;
        color: #3a3a3a;
    }
    .frame {
        margin: 36px 40px;
        height: 770px;
        border: 3px solid #b8860b;
        padding: 44px 36px;
        position: relative;
    }
    .title {
        text-align: center;
        font-size: 36px;
        color: #a0522d;
        letter-spacing: 10px;
        margin-top: 24px;
    }
    .rule {
        width: 100%;
        height: 2px;
        background-color: #b8860b;
        margin: 26px 0 42px;
    }
    .no { font-size: 12px; color: #666666; margin-bottom: 52px; }
    .congrats { font-size: 20px; margin-bottom: 30px; }
    .project { font-size: 16px; margin-bottom: 30px; }
    .amount { font-size: 28px; color: #b8860b; margin-bottom: 30px; }
    .thanks { font-size: 14px; line-height: 2.2; margin-bottom: 56px; }
    .date { font-size: 12px; color: #666666; margin-bottom: 18px; }
    .org { text-align: right; font-size: 12px; color: #a0522d; }
    .footer {
        position: absolute;
        bottom: 26px;
        left: 36px;
        right: 36px;
        font-size: 9px;
        color: #999999;
        text-align: center;
    }
</style>
</head>
<body>
<div class="frame">
    <div class="title">捐赠证书</div>
    <div class="rule"></div>

    <div class="no">证书编号：{{ $donationNo }}</div>
    <div class="congrats">恭喜：{{ $nickname }}</div>
    <div class="project">您向「{{ $projectTitle }}」项目捐赠了</div>
    <div class="amount">{{ $amount }} 元</div>
    <div class="thanks">
        成为了非遗保护与传承的支持者。<br>
        我们将与您一起，共同守护这份千年工艺。
    </div>

    <div class="date">颁发日期：{{ $date }}</div>
    <div class="org">焙箔凝艺·非遗保护机构</div>

    <div class="footer">本证书仅为感谢您的捐赠，不作为任何收据或税务凭证。</div>
</div>
</body>
</html>
