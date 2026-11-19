<ol class="steps" aria-label="挂号进度">
    <li>选择时段</li>
    <li @if($step === 2) aria-current="step" @endif>核对预约</li>
    <li @if($step === 3) aria-current="step" @endif>模拟微信支付</li>
</ol>
