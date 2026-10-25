@props(['current'])
<ol class="preparation-journey" aria-label="準備のステップ">
    @foreach(['関心を見つける', '結果をふりかえる', 'Futureを知る', '自分のPlanへ'] as $step)
        <li @if($loop->iteration === $current) aria-current="step" @endif>
            <span aria-hidden="true">{{ $loop->iteration }}</span>{{ $step }}
        </li>
    @endforeach
</ol>
