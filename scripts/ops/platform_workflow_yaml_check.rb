#!/usr/bin/env ruby
# Offline syntax-only validator for source-controlled GitHub Actions YAML.
# Outputs fixed classifications and filename/line only; never YAML snippets.
require 'psych'

if ARGV.empty?
  warn 'workflow_yaml_inputs_missing'
  exit 2
end

failed = false
ARGV.each do |path|
  unless File.file?(path) && !File.symlink?(path) && path.match?(/\.ya?ml\z/)
    warn 'workflow_yaml_file_missing_or_invalid'
    failed = true
    next
  end
  begin
    parsed = Psych.parse_file(path)
    unless parsed
      warn "workflow_yaml_empty file=#{File.basename(path)}"
      failed = true
    end
  rescue Psych::SyntaxError => e
    # Diagnostics deliberately do not print source lines or exception details.
    warn "workflow_yaml_syntax_error file=#{File.basename(path)} line=#{e.line}"
    failed = true
  rescue StandardError
    warn 'workflow_yaml_unavailable'
    failed = true
  end
end
if failed
  exit 2
end
puts "workflow_yaml_syntax_pass files=#{ARGV.length}"
