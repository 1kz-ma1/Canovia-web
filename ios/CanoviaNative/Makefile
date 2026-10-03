.PHONY: project clean

project:
	@command -v xcodegen >/dev/null 2>&1 || { echo "XcodeGen is required: brew install xcodegen"; exit 1; }
	xcodegen generate

clean:
	rm -rf CanoviaNative.xcodeproj
