@props(['name', 'category', 'label', 'checked' => false, 'art' => false])
<label class="preparation-interest-choice {{ $art ? 'has-art' : '' }}">
    <input type="checkbox" name="{{ $name }}[]" value="{{ $category }}" @checked($checked)>
    <span class="preparation-interest-tile">
        @if($art)
            <img src="{{ asset('images/preparation/'.strtolower($category).'.webp') }}" width="320" height="320" loading="lazy" decoding="async" alt="">
        @endif
        <span class="preparation-choice-label">{{ $label }}</span>
        <span class="preparation-choice-check" aria-hidden="true">✓</span>
    </span>
</label>
