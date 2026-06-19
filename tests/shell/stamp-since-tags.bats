#!/usr/bin/env bats
# Tests for bin/stamp-since-tags.sh
#
# Run with: bats tests/shell/stamp-since-tags.bats
# Install:  npm install --save-dev bats  OR  brew install bats-core

SCRIPT="$(cd "$(dirname "$BATS_TEST_FILENAME")/../.." && pwd)/bin/stamp-since-tags.sh"

setup() {
  TEST_ROOT="$(mktemp -d /tmp/stamp-since-tags-XXXXXX)"
  export STAMP_REPO_ROOT="$TEST_ROOT"
}

teardown() {
  rm -rf "$TEST_ROOT"
  unset STAMP_REPO_ROOT
}

@test "replaces @since NEXT_VERSION with the given version" {
  printf '<?php\n/** @since NEXT_VERSION */\nfunction foo() {}\n' > "$TEST_ROOT/subject.php"

  run "$SCRIPT" "1.9.0"

  [ "$status" -eq 0 ]
  grep -q "@since 1.9.0" "$TEST_ROOT/subject.php"
  ! grep -q "@since NEXT_VERSION" "$TEST_ROOT/subject.php"
}

@test "leaves files without the placeholder unchanged" {
  printf '<?php\n/** @since 1.0.0 */\nfunction bar() {}\n' > "$TEST_ROOT/stable.php"
  ORIGINAL="$(cat "$TEST_ROOT/stable.php")"
  # Provide a placeholder file so the script does not exit early.
  printf '<?php\n/** @since NEXT_VERSION */\n' > "$TEST_ROOT/needs-stamp.php"

  run "$SCRIPT" "2.0.0"

  [ "$status" -eq 0 ]
  [ "$(cat "$TEST_ROOT/stable.php")" = "$ORIGINAL" ]
}

@test "exits cleanly when no @since NEXT_VERSION placeholders are found" {
  printf '<?php\n/** @since 1.0.0 */\n' > "$TEST_ROOT/already-released.php"

  run "$SCRIPT" "1.9.0"

  [ "$status" -eq 0 ]
  [[ "$output" == *"nothing to stamp"* ]]
}

@test "does not stamp files inside vendor/" {
  mkdir -p "$TEST_ROOT/vendor/some-lib"
  printf '<?php\n/** @since NEXT_VERSION */\nfunction bar() {}\n' > "$TEST_ROOT/vendor/some-lib/bar.php"
  # Non-vendor placeholder so the script does not exit early with "nothing to stamp".
  printf '<?php\n/** @since NEXT_VERSION */\n' > "$TEST_ROOT/needs-stamp.php"

  run "$SCRIPT" "1.9.0"

  [ "$status" -eq 0 ]
  grep -q "@since NEXT_VERSION" "$TEST_ROOT/vendor/some-lib/bar.php"
  ! grep -q "@since 1.9.0" "$TEST_ROOT/vendor/some-lib/bar.php"
  grep -q "@since 1.9.0" "$TEST_ROOT/needs-stamp.php"
}
