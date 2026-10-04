@extends('layouts.app')

@section('title', 'プライバシーポリシー | Canovia')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <section class="page-card p-6 sm:p-8">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-sky-300">PRIVACY</p>
        <h1 class="mt-2 text-3xl font-black text-slate-50">プライバシーポリシー</h1>
        <p class="mt-3 text-sm leading-7 text-slate-400">最終更新: 2026年10月5日</p>

        <div class="mt-7 space-y-7 text-sm leading-7 text-slate-300">
            <section>
                <h2 class="text-lg font-bold text-slate-50">1. Canoviaが取り扱う情報</h2>
                <p class="mt-2">Canoviaは、サービス提供に必要な範囲で、アカウント情報（表示名・メールアドレス）、ユーザーが入力したPlan・Task・学習内容・フィードバック、アップロードした画像やファイル、利用状況・障害調査のための限定的な診断情報を取り扱います。</p>
                <p class="mt-2">パスワードは平文では保存せず、認証用のハッシュとして管理します。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">2. 利用目的</h2>
                <p class="mt-2">情報は、計画・学習・実行支援、データ同期、本人認証、品質改善、不具合調査、安全性確保、問い合わせ対応のために利用します。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">3. AI機能</h2>
                <p class="mt-2">AI機能を利用した場合、回答生成に必要な範囲の入力・関連Contextを、Canoviaが設定したAI提供者へ送信する場合があります。AIへ送る情報は機能ごとに必要範囲へ限定し、認証CookieやパスワードをAI入力として送信する設計にはしていません。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">4. 外部サービス連携</h2>
                <p class="mt-2">GitHub等の外部サービスをユーザーが明示的に接続した場合、接続状態・Repository情報・Activityなど、その連携機能の提供に必要な情報を取り扱うことがあります。外部サービスにはそれぞれのプライバシーポリシーが適用されます。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">5. 利用状況・診断</h2>
                <p class="mt-2">Canoviaは、画面性能、機能利用、エラー等を改善するため、必要最小限のTelemetryを記録する場合があります。広告目的のクロスサービス追跡を目的とした収集は、現在のCanovia Soft Launchでは行いません。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">6. 保存と削除</h2>
                <p class="mt-2">アカウント利用中は、機能提供に必要なデータを保存します。アカウント設定からアカウント削除を実行すると、本人所有Planと関連履歴、本人に紐づく主要な保存情報、アップロードファイルを削除します。</p>
                <p class="mt-2">他のユーザーが所有する共同Planについては、そのPlan全体を削除せず、削除ユーザーに直接紐づく情報を可能な範囲で除去します。法令上の保存義務が生じる情報がある場合は、その義務に従います。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">7. セキュリティ</h2>
                <p class="mt-2">通信のHTTPS化、認証情報の分離、CSRF保護、権限制御など、サービスの性質に応じた安全対策を行います。ただし、β / Soft Launch期間中は重要な機密情報をPlan本文へ入力しないことを推奨します。</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-50">8. 問い合わせ</h2>
                <p class="mt-2">プライバシーに関する問い合わせは、<a href="{{ route('legal.support') }}" class="font-semibold text-sky-300 hover:text-sky-200">Canoviaサポート</a>から送信できます。</p>
            </section>
        </div>
    </section>
</div>
@endsection
