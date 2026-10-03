<?php

namespace Tests\Feature;

use Tests\TestCase;

class IosShellPrototypeV521Test extends TestCase
{
    public function test_ios_prototype_uses_swiftui_and_one_persistent_wkwebview(): void
    {
        $project = file_get_contents(base_path('ios/CanoviaNative/project.yml'));
        $model = file_get_contents(base_path('ios/CanoviaNative/Sources/CanoviaWebViewModel.swift'));
        $app = file_get_contents(base_path('ios/CanoviaNative/Sources/CanoviaNativeApp.swift'));

        $this->assertStringContainsString('type: application', $project);
        $this->assertStringContainsString('platform: iOS', $project);
        $this->assertStringContainsString('deploymentTarget: "17.0"', $project);

        $this->assertStringContainsString('configuration.websiteDataStore = .default()', $model);
        $this->assertStringContainsString('let webView: WKWebView', $model);
        $this->assertStringContainsString('webView.allowsBackForwardNavigationGestures = true', $model);
        $this->assertStringContainsString('configuration.applicationNameForUserAgent = "CanoviaNative/iOS/', $model);
        $this->assertStringContainsString('injectionTime: .atDocumentStart', $model);

        $this->assertStringContainsString('@main', $app);
        $this->assertStringContainsString('CanoviaNativeApp: App', $app);
    }

    public function test_ios_prototype_targets_the_production_canovia_origin_and_same_origin_deep_links(): void
    {
        $environment = file_get_contents(base_path('ios/CanoviaNative/Sources/CanoviaEnvironment.swift'));
        $root = file_get_contents(base_path('ios/CanoviaNative/Sources/CanoviaRootView.swift'));
        $plist = file_get_contents(base_path('ios/CanoviaNative/Resources/Info.plist'));

        $this->assertStringContainsString(
            'https://pacekeeper-d3mm.onrender.com',
            $environment,
        );
        $this->assertStringContainsString('static func sameOriginPath(from url: URL)', $environment);
        $this->assertStringContainsString('host == originHost', $environment);
        $this->assertStringContainsString('.onOpenURL { url in', $root);

        $this->assertStringContainsString('<string>canovia</string>', $plist);
    }

    public function test_native_shell_keeps_external_navigation_and_file_input_boundaries_thin(): void
    {
        $model = file_get_contents(base_path('ios/CanoviaNative/Sources/CanoviaWebViewModel.swift'));
        $safari = file_get_contents(base_path('ios/CanoviaNative/Sources/SafariView.swift'));

        $this->assertStringContainsString('case "openExternal":', $model);
        $this->assertStringContainsString('externalURL = url', $model);
        $this->assertStringContainsString('case "fileInputRequested":', $model);
        $this->assertStringContainsString('SFSafariViewController', $safari);

        $this->assertStringNotContainsString('HTTPCookieStorage', $model);
        $this->assertStringNotContainsString('Authorization', $model);
        $this->assertStringNotContainsString('session_id', $model);
    }

    public function test_ios_prototype_has_camera_and_photo_usage_strings_but_no_production_signing_secret(): void
    {
        $plist = file_get_contents(base_path('ios/CanoviaNative/Resources/Info.plist'));
        $project = file_get_contents(base_path('ios/CanoviaNative/project.yml'));

        $this->assertStringContainsString('<key>NSCameraUsageDescription</key>', $plist);
        $this->assertStringContainsString('<key>NSPhotoLibraryUsageDescription</key>', $plist);
        $this->assertStringNotContainsString('DEVELOPMENT_TEAM:', $project);
        $this->assertStringContainsString('PRODUCT_BUNDLE_IDENTIFIER: app.canovia.prototype', $project);
    }

    public function test_prototype_runbook_documents_session_and_generation_boundaries(): void
    {
        $readme = file_get_contents(base_path('ios/CanoviaNative/README.md'));

        $this->assertStringContainsString('WKWebsiteDataStore.default()', $readme);
        $this->assertStringContainsString('brew install xcodegen', $readme);
        $this->assertStringContainsString('make project', $readme);
        $this->assertStringContainsString('an existing Safari/PWA login', $readme);
        $this->assertStringContainsString('Universal Links / Associated Domains', $readme);
    }
}
