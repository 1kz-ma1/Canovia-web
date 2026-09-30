<p class="canovia-map-note">
    @if ($isIntentHub)
        L0の固定Intentは常に残ります。Personalized Shortcutは既存Contextを最大2件だけ昇格するAttention表現です。自動signalまたは明示的な固定で近道を残せますが、元のPlan / Taskや優先度自体は変えません。
    @elseif ($isHierarchyLevel)
        この階層のNode位置は現在の構造から決定的に投影し、保存しません。Space Station DockはLevelを跨いで同じ位置に残ります。
    @else
        Execution中も右下のSpace Stationから入力・相談へ戻れます。詳細から操作した後は、意味のある状態差分だけ静かに再投影します。
    @endif
</p>
