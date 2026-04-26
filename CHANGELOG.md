## [1.2.1](https://github.com/niklas-joh/wp-ai-mind/compare/v1.2.0...v1.2.1) (2026-04-25)


### Bug Fixes

* **settings:** restore isPro field — revert erroneous canChat rename ([#249](https://github.com/niklas-joh/wp-ai-mind/issues/249)) ([d089509](https://github.com/niklas-joh/wp-ai-mind/commit/d089509a69489eaba912eed8159e86011abbb381))

# [1.2.0](https://github.com/niklas-joh/wp-ai-mind/compare/v1.1.0...v1.2.0) (2026-04-21)


### Features

* **workflow:** consolidate PR review nits into single issue ([#220](https://github.com/niklas-joh/wp-ai-mind/issues/220)) ([acc6b52](https://github.com/niklas-joh/wp-ai-mind/commit/acc6b5274ff833060c4051ecd245fcd3f997fe02)), closes [#d4c5f9](https://github.com/niklas-joh/wp-ai-mind/issues/d4c5f9) [#N](https://github.com/niklas-joh/wp-ai-mind/issues/N)

# [1.1.0](https://github.com/niklas-joh/wp-ai-mind/compare/v1.0.3...v1.1.0) (2026-04-14)


### Features

* **chat:** delete conversation with confirmation and inline error state ([#158](https://github.com/niklas-joh/wp-ai-mind/issues/158)) ([3c1312b](https://github.com/niklas-joh/wp-ai-mind/commit/3c1312b261ef093b801e42e508b11f46b0131e09)), closes [#117](https://github.com/niklas-joh/wp-ai-mind/issues/117) [#125](https://github.com/niklas-joh/wp-ai-mind/issues/125) [#129](https://github.com/niklas-joh/wp-ai-mind/issues/129) [#130](https://github.com/niklas-joh/wp-ai-mind/issues/130) [#131](https://github.com/niklas-joh/wp-ai-mind/issues/131) [#132](https://github.com/niklas-joh/wp-ai-mind/issues/132)

## [1.0.3](https://github.com/niklas-joh/wp-ai-mind/compare/v1.0.2...v1.0.3) (2026-04-14)


### Bug Fixes

* **deps:** add @wordpress/element to devDependencies alongside peerDependencies ([#159](https://github.com/niklas-joh/wp-ai-mind/issues/159)) ([673c19a](https://github.com/niklas-joh/wp-ai-mind/commit/673c19a004fb94b400f77bac3d7f29a59511d14d)), closes [#121](https://github.com/niklas-joh/wp-ai-mind/issues/121) [#126](https://github.com/niklas-joh/wp-ai-mind/issues/126)

## [1.0.2](https://github.com/niklas-joh/wp-ai-mind/compare/v1.0.1...v1.0.2) (2026-04-14)


### Bug Fixes

* **chat:** return full conversation on create; 500 on DB failure ([#155](https://github.com/niklas-joh/wp-ai-mind/issues/155)) ([65a1c4e](https://github.com/niklas-joh/wp-ai-mind/commit/65a1c4ef4c796bcda062d8751408652bd68bb135)), closes [#120](https://github.com/niklas-joh/wp-ai-mind/issues/120)

## [1.0.1](https://github.com/niklas-joh/wp-ai-mind/compare/v1.0.0...v1.0.1) (2026-04-14)


### Bug Fixes

* **tests:** guard putenv cleanup with try/finally ([#154](https://github.com/niklas-joh/wp-ai-mind/issues/154)) ([08f0a4c](https://github.com/niklas-joh/wp-ai-mind/commit/08f0a4c1416e6ec3b9d0fbb5fff69659dd689f37)), closes [#108](https://github.com/niklas-joh/wp-ai-mind/issues/108)

# 1.0.0 (2026-04-14)


### Bug Fixes

* **a11y:** associate form labels with controls, fix invalid href anchors ([e5aeb7e](https://github.com/niklas-joh/wp-ai-mind/commit/e5aeb7e7e8418968cef31b1784edd02fb6f7006e))
* add @wordpress/element as peer dependency ([7fa2142](https://github.com/niklas-joh/wp-ai-mind/commit/7fa2142ba04cd9d58a834ebc9ff5299f111bd909))
* add @wordpress/element as peer dependency ([#112](https://github.com/niklas-joh/wp-ai-mind/issues/112)) ([7bafa81](https://github.com/niklas-joh/wp-ai-mind/commit/7bafa81003aec175e0bf4973aca39bc5146316b5))
* add developer override for WP_AI_MIND_PRO in ProGate class ([225d22f](https://github.com/niklas-joh/wp-ai-mind/commit/225d22fe8fd6dae5cdbf02fbe9e2a79e6b57f7fd))
* address all code review issues from PR [#11](https://github.com/niklas-joh/wp-ai-mind/issues/11) ([#22](https://github.com/niklas-joh/wp-ai-mind/issues/22)) ([7b37fb7](https://github.com/niklas-joh/wp-ai-mind/commit/7b37fb78fd43ab0d1dd06803177d438f65fc1be5))
* address PR [#3](https://github.com/niklas-joh/wp-ai-mind/issues/3) review issues — security, XSS, and CI robustness ([d4db927](https://github.com/niklas-joh/wp-ai-mind/commit/d4db9273b7bee622412c7f97eeb5e3aebe30c8cd))
* address PR[#7](https://github.com/niklas-joh/wp-ai-mind/issues/7) review comments ([#17](https://github.com/niklas-joh/wp-ai-mind/issues/17)) ([b74217f](https://github.com/niklas-joh/wp-ai-mind/commit/b74217f979effc2521fcbc1eeb2f8711bb117f79))
* **chat:** show error message when no API key is configured ([eb24c4e](https://github.com/niklas-joh/wp-ai-mind/commit/eb24c4e5d07302c72cd8d9a1457c9e9e3394230c))
* **ci:** add .eslintignore and cap CI job timeouts ([#21](https://github.com/niklas-joh/wp-ai-mind/issues/21)) ([083093b](https://github.com/niklas-joh/wp-ai-mind/commit/083093bfbcf2ded53815216027b08cd44dab26f8))
* **ci:** add id-token: write to auto-fix workflow permissions ([#69](https://github.com/niklas-joh/wp-ai-mind/issues/69)) ([8514757](https://github.com/niklas-joh/wp-ai-mind/commit/8514757015af19085d7addd7aa36f6133b21a383))
* **ci:** add lint ignore files, stylelint config, and suppress phpcs warnings ([f96bf4b](https://github.com/niklas-joh/wp-ai-mind/commit/f96bf4b6d176c933146fb926410f7a5a45627c66))
* **ci:** resolve PHPCS and PHPUnit CI failures ([#7](https://github.com/niklas-joh/wp-ai-mind/issues/7)) ([260b0ca](https://github.com/niklas-joh/wp-ai-mind/commit/260b0ca30f258afd23a4e9216fcf28c9966eb402))
* **ci:** skip lockfile PRs and make Claude Code Review non-blocking ([efee6e3](https://github.com/niklas-joh/wp-ai-mind/commit/efee6e3ae86f32a881c47ca2438749f8cb81b97b)), closes [#78](https://github.com/niklas-joh/wp-ai-mind/issues/78)
* **ci:** skip lockfile PRs and make Claude Code Review non-blocking ([#94](https://github.com/niklas-joh/wp-ai-mind/issues/94)) ([a0e343d](https://github.com/niklas-joh/wp-ai-mind/commit/a0e343d551a4ceab098bed7b1121e8d705922b18))
* **ci:** sync all workflow fixes from develop to main ([70300fd](https://github.com/niklas-joh/wp-ai-mind/commit/70300fdfd13d3126e70d98ecdd00aa4c60064b9e))
* **ci:** sync all workflow fixes from develop to main (hotfix) ([#145](https://github.com/niklas-joh/wp-ai-mind/issues/145)) ([7c59513](https://github.com/niklas-joh/wp-ai-mind/commit/7c59513e3b05756e1eb82e173bd66e3103a2e88f))
* **ci:** sync package-lock.json with package.json ([a2e3def](https://github.com/niklas-joh/wp-ai-mind/commit/a2e3def21680d55cb4fc4768f86cb71e23b1ded2))
* **ci:** sync package-lock.json with package.json ([#92](https://github.com/niklas-joh/wp-ai-mind/issues/92)) ([3e81721](https://github.com/niklas-joh/wp-ai-mind/commit/3e81721754b57b1840b213459a21ea8825393951)), closes [#78](https://github.com/niklas-joh/wp-ai-mind/issues/78)
* **ci:** use temp file for CHANGELOG prepend to avoid sed multiline failure (closes [#82](https://github.com/niklas-joh/wp-ai-mind/issues/82)) ([b32daaa](https://github.com/niklas-joh/wp-ai-mind/commit/b32daaa9c1e8b1c2eb397169a82899d307ccc800))
* **claude:** correct PR base branch rule and add enforcement hook ([a7fe60c](https://github.com/niklas-joh/wp-ai-mind/commit/a7fe60cf74c14fc5b5b16bbd3da9046a91f28bd7)), closes [#107](https://github.com/niklas-joh/wp-ai-mind/issues/107)
* **claude:** correct PR base branch rule and add enforcement hook ([92a29f8](https://github.com/niklas-joh/wp-ai-mind/commit/92a29f87b5901466d8b2e151e2e00e477e7da5f1)), closes [#107](https://github.com/niklas-joh/wp-ai-mind/issues/107)
* **claude:** correct PR base branch rule and add enforcement hook ([#113](https://github.com/niklas-joh/wp-ai-mind/issues/113)) ([bc800f7](https://github.com/niklas-joh/wp-ai-mind/commit/bc800f734792fa7ade28f052fcdb63a50caef5b8))
* **css:** resolve all CSS lint errors to make CI green ([#20](https://github.com/niklas-joh/wp-ai-mind/issues/20)) ([a0de774](https://github.com/niklas-joh/wp-ai-mind/commit/a0de7749aa7a2fed24c53d5a74148319386a8e3d))
* **dashboard:** add missing settings CSS classes, null-guard wpAiMindData, remove dev note HTML ([ecbf97e](https://github.com/niklas-joh/wp-ai-mind/commit/ecbf97ec0adee6abe2935b6d4da74b4649522d95))
* **dashboard:** harden OnboardingRestController — manage_options, sanitize api_key, injectable ProviderSettings ([6c528f6](https://github.com/niklas-joh/wp-ai-mind/commit/6c528f6e622af79a5a19286545b269ef65b166c2))
* **dashboard:** strengthen capability check and add PRG redirect for run_setup action ([a9ecddf](https://github.com/niklas-joh/wp-ai-mind/commit/a9ecddf7d6e085158c6f51a0712bb8ac76fe89b0))
* **e2e:** auto-install WP-CLI in Docker and use nj_agent in p1 spec ([6a87ab1](https://github.com/niklas-joh/wp-ai-mind/commit/6a87ab12aef8b66c124e427e982eafe8993d59a3))
* **eslint:** resolve accessibility, no-console, and code errors in JSX ([5f38ca6](https://github.com/niklas-joh/wp-ai-mind/commit/5f38ca6bbaaada731a3ed78f0646233235015612))
* guard WP_AI_MIND_PRO override behind WP_DEBUG ([9a4568f](https://github.com/niklas-joh/wp-ai-mind/commit/9a4568fe6470809f0ea02d2116bf5289a83b1e4e))
* **hooks:** allow hotfix/* branches to target main in PR base check ([e116acd](https://github.com/niklas-joh/wp-ai-mind/commit/e116acd9cf62bb89f9079f5979d5675256b1b55b))
* **lint:** ignore @wordpress/* in import/no-unresolved ([cd00f5e](https://github.com/niklas-joh/wp-ai-mind/commit/cd00f5ed8c9cf46d63ecb4d7243fcad1b4cff2ef))
* **lint:** resolve JS and CSS linter errors ([#5](https://github.com/niklas-joh/wp-ai-mind/issues/5)) ([560562b](https://github.com/niklas-joh/wp-ai-mind/commit/560562b9263bc87bdecb188e8b1517ca00dbd165)), closes [#fff](https://github.com/niklas-joh/wp-ai-mind/issues/fff)
* miscellaneous PHP, JS, CSS and security fixes ([#19](https://github.com/niklas-joh/wp-ai-mind/issues/19)) ([4b11607](https://github.com/niklas-joh/wp-ai-mind/commit/4b1160780db47a52099000d5463b60236b34cbc5)), closes [#000](https://github.com/niklas-joh/wp-ai-mind/issues/000) [#11](https://github.com/niklas-joh/wp-ai-mind/issues/11)
* **p1:** strict_types in uninstall.php, autoloader + plugin stubs to prevent fatal on activation ([b12b700](https://github.com/niklas-joh/wp-ai-mind/commit/b12b70010cc5a1d1ceede46c47a66c01e3d2b862))
* **p3:** global namespace prefix + ProGate eager-load + E2E credential fixes ([c6f1723](https://github.com/niklas-joh/wp-ai-mind/commit/c6f17232a815231df8a27c5ed6ad894b411097a0))
* paginate PostListTable to fetch all posts/pages beyond 100 ([#77](https://github.com/niklas-joh/wp-ai-mind/issues/77)) ([fc557a3](https://github.com/niklas-joh/wp-ai-mind/commit/fc557a3801f5d8c1b965cb2021d4c39cf687d3bb))
* **phpcs:** resolve short ternary, empty catch, and array formatting errors ([84fffe6](https://github.com/niklas-joh/wp-ai-mind/commit/84fffe672416d871069a334e2dcf05217c216657))
* **phpunit:** add missing mock methods to prevent fatal errors ([571bc5c](https://github.com/niklas-joh/wp-ai-mind/commit/571bc5c40f884a58d2e2ee45a75300a6d9cfca73))
* **PostListTable:** check response.ok after parse:false to surface WP REST errors ([#72](https://github.com/niklas-joh/wp-ai-mind/issues/72)) ([0911459](https://github.com/niklas-joh/wp-ai-mind/commit/0911459e36d9598ad6cf991db70b59669f6b48f3))
* **PostListTable:** check response.ok after parse:false to surface WP REST errors (closes [#23](https://github.com/niklas-joh/wp-ai-mind/issues/23)) ([5f7202e](https://github.com/niklas-joh/wp-ai-mind/commit/5f7202e9a2ea548d035594fa4c5660911e133d5b))
* **PostListTable:** paginate all posts/pages via X-WP-TotalPages (closes [#26](https://github.com/niklas-joh/wp-ai-mind/issues/26)) ([588f918](https://github.com/niklas-joh/wp-ai-mind/commit/588f9180f84d95efcc971fa6b524d505ff238f13))
* **prepare:** resolve .git hooks dir via git rev-parse for submodule compatibility ([5e09385](https://github.com/niklas-joh/wp-ai-mind/commit/5e0938509e26ed062302544477fb3a1b24370659))
* register SeoModule and ImagesModule in Plugin bootstrap ([4c098ee](https://github.com/niklas-joh/wp-ai-mind/commit/4c098ee43cb99f965fc48a36f1e7d38fb60ca95a))
* **release:** make conflict-resolution status check resilient ([b76e796](https://github.com/niklas-joh/wp-ai-mind/commit/b76e7965e3291b12d1ae54be749c4fcdd97a2164))
* **release:** make conflict-resolution status check resilient (hotfix) ([#147](https://github.com/niklas-joh/wp-ai-mind/issues/147)) ([538e937](https://github.com/niklas-joh/wp-ai-mind/commit/538e937fa9615944523c2162e88ce01c9a5dd63e))
* remove stale WP_AI_MIND_PRO constant from ProGate ([ea677ab](https://github.com/niklas-joh/wp-ai-mind/commit/ea677ab735c2a77ef04c36d96ca6c6d3acab7744))
* **review:** use PAT so auto-fix label fires issues:labeled event ([37adfdf](https://github.com/niklas-joh/wp-ai-mind/commit/37adfdfb249ac14963618139b429bc33e00ac650))
* **review:** use PAT so auto-fix label fires issues:labeled event ([#110](https://github.com/niklas-joh/wp-ai-mind/issues/110)) ([2307236](https://github.com/niklas-joh/wp-ai-mind/commit/23072366f5320a7cfd5383dd0e87d5121d363cca))
* **seo:** avoid extra get_post() call in get_seo_status() ([#75](https://github.com/niklas-joh/wp-ai-mind/issues/75)) ([9578910](https://github.com/niklas-joh/wp-ai-mind/commit/957891099d5c4b26c09689f1b9489351273741a6))
* **seo:** avoid extra get_post() call in get_seo_status() (closes [#31](https://github.com/niklas-joh/wp-ai-mind/issues/31)) ([116dacf](https://github.com/niklas-joh/wp-ai-mind/commit/116dacfee77d1972a73854680f023eafa0838c0c))
* **seo:** correct indentation on yesButtonRef prop ([8ec3e3a](https://github.com/niklas-joh/wp-ai-mind/commit/8ec3e3a0d2652503d9b804eeac38b62387aeb706))
* **seo:** disable Generate button during confirm and add alertdialog role ([#102](https://github.com/niklas-joh/wp-ai-mind/issues/102)) ([0c75348](https://github.com/niklas-joh/wp-ai-mind/commit/0c75348291e2045e373c5bc3c8be9f2eeec551f7))
* **seo:** disable Generate button during confirm and add alertdialog role (closes [#29](https://github.com/niklas-joh/wp-ai-mind/issues/29)) ([27e3ae1](https://github.com/niklas-joh/wp-ai-mind/commit/27e3ae10cf484074bc4b42c324a9fb9a5d6cb5d3))
* **seo:** log exceptions server-side, return generic REST error messages ([#73](https://github.com/niklas-joh/wp-ai-mind/issues/73)) ([594df7a](https://github.com/niklas-joh/wp-ai-mind/commit/594df7a5d9f2cf03e360f7ec61480d1a2b6da08d))
* **seo:** log exceptions server-side, return generic REST error messages (closes [#30](https://github.com/niklas-joh/wp-ai-mind/issues/30)) ([0d8f17d](https://github.com/niklas-joh/wp-ai-mind/commit/0d8f17da0bc604b461444331f6d8e251b5268c2e))
* **seo:** replace autoFocus with useRef/useEffect and fix prettier formatting ([7724609](https://github.com/niklas-joh/wp-ai-mind/commit/7724609de155027e2539919487b58ae283db412c))
* **shared:** fix lint errors after navigator.language change ([9e500b5](https://github.com/niklas-joh/wp-ai-mind/commit/9e500b5be666195ebcfa71f8e60659823873f7cd))
* **shared:** use navigator.language for date locale in PostListTable ([#74](https://github.com/niklas-joh/wp-ai-mind/issues/74)) ([6d24b22](https://github.com/niklas-joh/wp-ai-mind/commit/6d24b227ad7b89ea6eba83a7337a7bc9efdd6858))
* **shared:** use navigator.language for date locale in PostListTable (closes [#35](https://github.com/niklas-joh/wp-ai-mind/issues/35)) ([791c338](https://github.com/niklas-joh/wp-ai-mind/commit/791c338f08265067cfa406a2e68dd38d383ee6c1))
* **stylelint:** resolve descending specificity and CSS formatting violations ([bc86ab7](https://github.com/niklas-joh/wp-ai-mind/commit/bc86ab7ecaf0d33357bb9739be3e0f56f17c3173)), closes [#000](https://github.com/niklas-joh/wp-ai-mind/issues/000)
* **tokens:** add missing --space-9 and --space-12 CSS custom properties ([#14](https://github.com/niklas-joh/wp-ai-mind/issues/14)) ([6226ebd](https://github.com/niklas-joh/wp-ai-mind/commit/6226ebdca86233988c985303d5a29c6b042b5d05))
* **tools:** emit {} not [] for empty tool properties; improve ProviderException HTTP status passthrough ([c179d8e](https://github.com/niklas-joh/wp-ai-mind/commit/c179d8ee9a7396e3d1e6b4cc0e8b6d8edb375ce6))
* **tools:** handle multi-tool-use and empty input in Claude tool exchange ([f2a052a](https://github.com/niklas-joh/wp-ai-mind/commit/f2a052aad269f268ccbd577808b4e372f15fc0d8))
* **ui:** add CSS fallbacks for color-mix() in admin.css (closes [#55](https://github.com/niklas-joh/wp-ai-mind/issues/55)) ([cd83254](https://github.com/niklas-joh/wp-ai-mind/commit/cd832545adaf7fa8e554c7b7262bc81030933399))
* **ui:** add missing --space-7 token and increase dashboard tile padding ([#4](https://github.com/niklas-joh/wp-ai-mind/issues/4)) ([71aa923](https://github.com/niklas-joh/wp-ai-mind/commit/71aa92343c7057473453bc8802053593554ace61))
* **ui:** migrate all plugin pages to WP admin light colour palette ([77d0685](https://github.com/niklas-joh/wp-ai-mind/commit/77d06853ec6a82acb3d946877b919c220e446a9a))
* **ui:** migrate all plugin pages to WP admin light colour palette ([#50](https://github.com/niklas-joh/wp-ai-mind/issues/50)) ([cd510d4](https://github.com/niklas-joh/wp-ai-mind/commit/cd510d41a8f6c94780af0f63eb5d3bfbded76fd3))
* **ui:** migrate to WP admin light palette across all plugin pages ([0b61c31](https://github.com/niklas-joh/wp-ai-mind/commit/0b61c316a6d7851a15657686fbdcee24e8476256)), closes [#fff](https://github.com/niklas-joh/wp-ai-mind/issues/fff) [#f8f9fa](https://github.com/niklas-joh/wp-ai-mind/issues/f8f9fa) [#dcdcde](https://github.com/niklas-joh/wp-ai-mind/issues/dcdcde) [#1d2327](https://github.com/niklas-joh/wp-ai-mind/issues/1d2327)
* **ui:** restore .wpaim-toggle styles removed in PR [#50](https://github.com/niklas-joh/wp-ai-mind/issues/50) (closes [#53](https://github.com/niklas-joh/wp-ai-mind/issues/53)) ([861d69d](https://github.com/niklas-joh/wp-ai-mind/commit/861d69d8542ae4e17fae7227020658d92b06983e))
* **workflow:** remove auto-fix from initial issue labels to prevent duplicate labeling ([34b7dd9](https://github.com/niklas-joh/wp-ai-mind/commit/34b7dd90c645aaf6c2ff3f530f1affe70f8e65cf)), closes [#34](https://github.com/niklas-joh/wp-ai-mind/issues/34)
* **workflow:** remove auto-fix from initial issue labels to prevent duplicate labeling ([#101](https://github.com/niklas-joh/wp-ai-mind/issues/101)) ([8d382ce](https://github.com/niklas-joh/wp-ai-mind/commit/8d382ce17fbb19bce952675e9f11d16e16b4dcf2))
* **workflows:** apply auto-fix label in separate step to trigger auto-fix workflow ([2a6ae62](https://github.com/niklas-joh/wp-ai-mind/commit/2a6ae620ba71e7a9fa1856ac2b5366699f06f880))
* **workflows:** apply auto-fix label in separate step to trigger auto-fix workflow ([#91](https://github.com/niklas-joh/wp-ai-mind/issues/91)) ([3fb7ef8](https://github.com/niklas-joh/wp-ai-mind/commit/3fb7ef81bae6dcaef969f4ccb2c502a4fe7e5580))
* **workflows:** apply fixes for [#86](https://github.com/niklas-joh/wp-ai-mind/issues/86), [#87](https://github.com/niklas-joh/wp-ai-mind/issues/87), [#89](https://github.com/niklas-joh/wp-ai-mind/issues/89) from claude/fix-branch-collision-n19XO ([7b484f2](https://github.com/niklas-joh/wp-ai-mind/commit/7b484f23d88d4967c56cd2ccc2fa93ee159131d9))
* **workflows:** delete stale release/staging branch before checkout (closes [#89](https://github.com/niklas-joh/wp-ai-mind/issues/89)) ([1034d41](https://github.com/niklas-joh/wp-ai-mind/commit/1034d41580795803ecd6c678a9aa7687fff0df74))
* **workflows:** grant workflows write permission to Claude Code action ([#43](https://github.com/niklas-joh/wp-ai-mind/issues/43)) ([f758d4c](https://github.com/niklas-joh/wp-ai-mind/commit/f758d4c6d90d19731a50e9c61e2dbd625d19d881))
* **workflows:** harden build-release-branch.yml against shell injection (closes [#86](https://github.com/niklas-joh/wp-ai-mind/issues/86), closes [#87](https://github.com/niklas-joh/wp-ai-mind/issues/87)) ([3dd2186](https://github.com/niklas-joh/wp-ai-mind/commit/3dd218698ce8a598b2ce35fcc29a285a2372bf34))
* **workflow:** skip Claude review on PRs that modify workflow files ([4f77a16](https://github.com/niklas-joh/wp-ai-mind/commit/4f77a160f14b34cff14b5ef598c1d5730aedeb95))
* **workflow:** skip Claude review on PRs that modify workflow files ([#103](https://github.com/niklas-joh/wp-ai-mind/issues/103)) ([622b500](https://github.com/niklas-joh/wp-ai-mind/commit/622b500de6395dcfc4d2f7c969a701bc9e2d98ee))
* **workflows:** pin actions/checkout and claude-code-action to full commit SHAs ([#45](https://github.com/niklas-joh/wp-ai-mind/issues/45)) ([a4f0e6a](https://github.com/niklas-joh/wp-ai-mind/commit/a4f0e6ad05ce24c8413f79953ef4f98b9531ba98))
* **workflows:** pin actions/github-script to full commit SHA (closes [#65](https://github.com/niklas-joh/wp-ai-mind/issues/65)) ([c45200a](https://github.com/niklas-joh/wp-ai-mind/commit/c45200a8ddf048ed566321f91169894e3c6663d4))
* **workflows:** pin actions/github-script to full commit SHA (closes [#65](https://github.com/niklas-joh/wp-ai-mind/issues/65)) ([#96](https://github.com/niklas-joh/wp-ai-mind/issues/96)) ([459a55e](https://github.com/niklas-joh/wp-ai-mind/commit/459a55e71e4f9c40caababd3256abbce6996824c))
* **workflows:** prevent bot-created issues from triggering claude.yml ([3c0357b](https://github.com/niklas-joh/wp-ai-mind/commit/3c0357b610ac1649611a0341f6c963c8f19e4fd5)), closes [#44](https://github.com/niklas-joh/wp-ai-mind/issues/44)
* **workflows:** prevent bot-created issues from triggering claude.yml ([#79](https://github.com/niklas-joh/wp-ai-mind/issues/79)) ([efcc689](https://github.com/niklas-joh/wp-ai-mind/commit/efcc6894832d2dfdb5435180bd9ee45efaf25b2e))
* **workflows:** remove invalid 'workflows: write' permission from claude.yml ([a941488](https://github.com/niklas-joh/wp-ai-mind/commit/a941488bbb74deb86c8c0947de283cbdfbb659de))
* **workflows:** remove invalid 'workflows: write' permission from claude.yml ([#95](https://github.com/niklas-joh/wp-ai-mind/issues/95)) ([caf25a9](https://github.com/niklas-joh/wp-ai-mind/commit/caf25a927f260566c4b2c84089a0d79b7ec228d7))
* **workflows:** repair claude-code-review so it raises and auto-fixes issues ([#59](https://github.com/niklas-joh/wp-ai-mind/issues/59)) ([1a91771](https://github.com/niklas-joh/wp-ai-mind/commit/1a91771e776e93cfaf11e32e8a436f59801ab512))
* **workflows:** replace broken backfill-merged-tags with backfill-semantic-tags ([#142](https://github.com/niklas-joh/wp-ai-mind/issues/142)) ([926e199](https://github.com/niklas-joh/wp-ai-mind/commit/926e199c39f6b2361489f373377801c2f2dc1dde))
* **workflows:** sanitize github.event.issue.html_url interpolation (closes [#68](https://github.com/niklas-joh/wp-ai-mind/issues/68)) ([0f884b3](https://github.com/niklas-joh/wp-ai-mind/commit/0f884b35c0623ae02e364e6fd211d01af3d83092))
* **workflows:** sanitize remaining html_url interpolation in auto-fix prompt ([#71](https://github.com/niklas-joh/wp-ai-mind/issues/71)) ([1d7f2c6](https://github.com/niklas-joh/wp-ai-mind/commit/1d7f2c618a09941e0ae9de84a19a9e71c2aec385))
* **workflows:** sanitize workflow_run inputs via env map (closes [#28](https://github.com/niklas-joh/wp-ai-mind/issues/28)) ([0ee3e1b](https://github.com/niklas-joh/wp-ai-mind/commit/0ee3e1bcb5553790f1a5e67ffe06d64accf53054))
* **workflows:** sanitize workflow_run inputs via env map (closes [#28](https://github.com/niklas-joh/wp-ai-mind/issues/28)) ([#99](https://github.com/niklas-joh/wp-ai-mind/issues/99)) ([0b891c6](https://github.com/niklas-joh/wp-ai-mind/commit/0b891c63485d79a2111bec6bdba019b15b850eea))
* **workflows:** security and CI hardening — backport from develop ([#67](https://github.com/niklas-joh/wp-ai-mind/issues/67)) ([39ceef6](https://github.com/niklas-joh/wp-ai-mind/commit/39ceef6910abd5cc10c6831fbe6c6a394fe02838))
* **workflows:** use step output instead of exit 0 for duplicate-tag guard ([09f27a1](https://github.com/niklas-joh/wp-ai-mind/commit/09f27a1029c524cd58874e38e540e59eea44c587)), closes [#80](https://github.com/niklas-joh/wp-ai-mind/issues/80)
* **wp-ai-mind:** fix Playwright E2E login timeout via global user setup (M2) ([e2c3446](https://github.com/niklas-joh/wp-ai-mind/commit/e2c344622bc70e758d57ac3d75a2f3c0c81018c4))
* **wp-ai-mind:** QA audit Week 1 — security, standards, and submission readiness ([3190fad](https://github.com/niklas-joh/wp-ai-mind/commit/3190fadcc851ceb275dac82e86b7b8457df45391))
* **wp-ai-mind:** resolve 6 QA-reported bugs (BUG-01/02/04/05/06/07/08) ([d410444](https://github.com/niklas-joh/wp-ai-mind/commit/d410444fd1bcdc1d94de520a5a923138fe091fa6))


### Features

* add CI/CD workflows, v0.2.0 bump, release process, fix build script vendor ([f2013ca](https://github.com/niklas-joh/wp-ai-mind/commit/f2013ca40b7238ee100e420d21e0a24a6a7c9142))
* add Images admin page — ImagesApp, ImagesBadge, ImagesWorkArea, CSS ([79b59a9](https://github.com/niklas-joh/wp-ai-mind/commit/79b59a9eff7c378871b2e5fc858ba4862dbebdc2))
* add SEO admin page — SeoApp, SeoBadge, SeoWorkArea, CSS ([a46d878](https://github.com/niklas-joh/wp-ai-mind/commit/a46d8785ee5569e0d3624dcb347e59894b8103cf))
* add SEO and Images admin pages ([#11](https://github.com/niklas-joh/wp-ai-mind/issues/11)) ([6e641c2](https://github.com/niklas-joh/wp-ai-mind/commit/6e641c21e8303d34713fe8317962a404ed558add)), closes [#9](https://github.com/niklas-joh/wp-ai-mind/issues/9)
* add shared PostListTable component and base CSS ([953ffc5](https://github.com/niklas-joh/wp-ai-mind/commit/953ffc52ccd909a48888cdfb925c7b4b7f950951))
* add wp_ai_mind_is_pro filter for local dev override ([367817c](https://github.com/niklas-joh/wp-ai-mind/commit/367817ca503a02404b49807c44aede26165fffe7))
* add wp_ai_mind_is_pro filter for local dev override ([5b5bb42](https://github.com/niklas-joh/wp-ai-mind/commit/5b5bb429f1bffca3967366b051674de37712f733))
* **chat:** context picker, model selector redesign, search-posts endpoint ([1008ea5](https://github.com/niklas-joh/wp-ai-mind/commit/1008ea5e4a8dcbae769ced39742b33773a3bba87))
* **ci:** add backfill-semantic-tags workflow for retroactive tagging ([5043a9f](https://github.com/niklas-joh/wp-ai-mind/commit/5043a9fcbb332cdd25a0262a7a89c11fe6805775))
* **dashboard:** add dashboard CSS using existing design tokens ([1f93f96](https://github.com/niklas-joh/wp-ai-mind/commit/1f93f968b452ef3bd0252c8155896fdffbc28106))
* **dashboard:** add DashboardPage PHP class with localized data ([80f1723](https://github.com/niklas-joh/wp-ai-mind/commit/80f17230307c91e1ad6d5a48728160d52800c8dd))
* **dashboard:** add OnboardingModal component — branching Step1 / Step2 / Done ([44668a6](https://github.com/niklas-joh/wp-ai-mind/commit/44668a62f2967e427e20b237c3170bde9f884e93))
* **dashboard:** add OnboardingRestController — POST /wp-ai-mind/v1/onboarding ([7fb919a](https://github.com/niklas-joh/wp-ai-mind/commit/7fb919a2ef7494aa8416fb1723b6cdc518a7c7da))
* **dashboard:** add ResourceList and PageFooter components ([17f7418](https://github.com/niklas-joh/wp-ai-mind/commit/17f7418d2872e26a762efafe63ecc5398d304e03))
* **dashboard:** add StartTiles component ([e9e1a93](https://github.com/niklas-joh/wp-ai-mind/commit/e9e1a9351c2b1563b3036849123ae0d9fab1df56))
* **dashboard:** add StatusBanner component ([ff82103](https://github.com/niklas-joh/wp-ai-mind/commit/ff82103173846a37cbe95387eb24705639a3bf50))
* **dashboard:** assemble DashboardApp — all components wired ([e0b7966](https://github.com/niklas-joh/wp-ai-mind/commit/e0b7966e3b52c8e4e8805fdbb4d0eee25e1d7dd1))
* **dashboard:** restructure admin menu — Dashboard as top-level entry point ([c1d5069](https://github.com/niklas-joh/wp-ai-mind/commit/c1d5069537c8aca2a2898932955845b1567873e2))
* **dashboard:** wire dashboard React mount point ([b1f1363](https://github.com/niklas-joh/wp-ai-mind/commit/b1f1363f5d543bfdb4d33dadfa34dcfba561ff39))
* implement SEO & Images admin pages with shared PostListTable ([#61](https://github.com/niklas-joh/wp-ai-mind/issues/61)) ([846aabb](https://github.com/niklas-joh/wp-ai-mind/commit/846aabb8818ac4ed9fde4056bf0714c18f8b9db0))
* migrate admin UI controls to @wordpress/components (Session 2) ([cf86bee](https://github.com/niklas-joh/wp-ai-mind/commit/cf86bee41e938a99763cfbe3bd6f77a010886aed))
* migrate admin UI controls to @wordpress/components (Session 2) ([#111](https://github.com/niklas-joh/wp-ai-mind/issues/111)) ([634fd5e](https://github.com/niklas-joh/wp-ai-mind/commit/634fd5e3d25a610957baa688ffa88f5e13a5ad95))
* nightly sync workflow — main → develop with Claude conflict resolution ([#39](https://github.com/niklas-joh/wp-ai-mind/issues/39)) ([6d0fc1d](https://github.com/niklas-joh/wp-ai-mind/commit/6d0fc1decdc3a46f3f57fe37adcbf7c99dba19cc))
* onboarding — WP components migration + per-provider API keys ([#16](https://github.com/niklas-joh/wp-ai-mind/issues/16)) ([4c0252d](https://github.com/niklas-joh/wp-ai-mind/commit/4c0252d1af317cfb2e43ecf8360b44bb6b19254c))
* **onboarding:** full-page layout, per-provider keys, test-key endpoint ([2f3702a](https://github.com/niklas-joh/wp-ai-mind/commit/2f3702a99064d7c5c32beecffe20e29b74f81073)), closes [2563eb/#1d4ed8](https://github.com/niklas-joh/wp-ai-mind/issues/1d4ed8)
* **p1:** @wordpress/scripts build scaffold, CSS design tokens ([d4687d9](https://github.com/niklas-joh/wp-ai-mind/commit/d4687d98308e25261db925806181fa5f593fa9ea))
* **p1:** admin menu skeleton with Lucide sparkles icon ([19efa43](https://github.com/niklas-joh/wp-ai-mind/commit/19efa4346b6c76a9906a58cd0e27bc04010a5430))
* **p1:** DB schema — usage_log, conversations, messages tables ([72e8f4a](https://github.com/niklas-joh/wp-ai-mind/commit/72e8f4a669fc21b2357544f79c18245c98e6b4d3))
* **p1:** encrypted API key storage ([9e84e9c](https://github.com/niklas-joh/wp-ai-mind/commit/9e84e9c96f89ae2e49a3d5ce82d169526846eddd))
* **p1:** module registry with toggle persistence ([7211ffa](https://github.com/niklas-joh/wp-ai-mind/commit/7211ffa3fbb64ae19988cb21fc6020d6006c116a))
* **p1:** plugin scaffold, composer, phpunit config, PHPCS standard ([57708d5](https://github.com/niklas-joh/wp-ai-mind/commit/57708d59beec4c68095dc0566c58a4768adca3c4))
* **p1:** Plugin singleton with activation hooks ([1afcf30](https://github.com/niklas-joh/wp-ai-mind/commit/1afcf30df76b3b631554965e2e3a2a16a91e42c6))
* **p1:** ProGate abstraction + wp_ai_mind_is_pro() helper ([157d41d](https://github.com/niklas-joh/wp-ai-mind/commit/157d41d25839d1fe415f64395620982af2d45ad8))
* **p1:** PSR-4 autoloader with tests ([619e3ba](https://github.com/niklas-joh/wp-ai-mind/commit/619e3ba9c98233ee55003364d74e4f3e18039bc1))
* **p2-task3:** ClaudeProvider with cost calculation ([5e97323](https://github.com/niklas-joh/wp-ai-mind/commit/5e97323b5fa7cd84d61561e7e36342b1ebc46866))
* **p2:** AbstractProvider with retry + usage logging ([c888c5e](https://github.com/niklas-joh/wp-ai-mind/commit/c888c5ec8924b776ace963de3ffde34f14b3219c))
* **p2:** GeminiProvider with Imagen 3 image generation ([efbf31d](https://github.com/niklas-joh/wp-ai-mind/commit/efbf31dc6dd4d50a9f360cb2069bff3213255c32))
* **p2:** OllamaProvider for local inference ([3660322](https://github.com/niklas-joh/wp-ai-mind/commit/366032245eafddade738e5b29059f6afe9344a2b))
* **p2:** OpenAIProvider with DALL-E 3 image generation ([e9f755a](https://github.com/niklas-joh/wp-ai-mind/commit/e9f755a777c7bd500abe31fdf6f09b45bc4cff3e))
* **p2:** ProviderFactory ([54ee14b](https://github.com/niklas-joh/wp-ai-mind/commit/54ee14b63fe65b454cb3afe7378fa1624f07249a))
* **p2:** ProviderInterface, value objects, ProviderException + HTTP timeout constant ([fdc4c70](https://github.com/niklas-joh/wp-ai-mind/commit/fdc4c706af6d41d7d0aa22b9dd5f93a495c1603e))
* **p2:** VoiceInjector — site + user voice merge ([78f6f0d](https://github.com/niklas-joh/wp-ai-mind/commit/78f6f0de3b9f14a237b106ad08282b795ed62c30))
* **p3:** Chat REST API — conversations + messages + providers ([a3ee218](https://github.com/niklas-joh/wp-ai-mind/commit/a3ee2181c9629072f241030a6c9f26146bcc8ab8))
* **p3:** ConversationStore CRUD ([7a403b6](https://github.com/niklas-joh/wp-ai-mind/commit/7a403b6bb421b18f3887194f1f2059e1532fbc0c))
* **p3:** localise wpAiMindData for React app ([8bc8a1c](https://github.com/niklas-joh/wp-ai-mind/commit/8bc8a1c8d53e06e750f0927397badef2a814571d))
* **p3:** React Chat UI — shell, components, and compiled assets ([916c2df](https://github.com/niklas-joh/wp-ai-mind/commit/916c2df7844655b41fa08b794c102fc5469e4d72))
* **p3:** Settings UI — Providers, Voice, Features tabs ([ebe59bf](https://github.com/niklas-joh/wp-ai-mind/commit/ebe59bf6f5d32b43ef9664c84caaa46bc2f2dc82))
* **p3:** SettingsRestController + SettingsPage enqueue ([071d78d](https://github.com/niklas-joh/wp-ai-mind/commit/071d78db7a91d3fb8e1717d749f4657c9787e57a))
* **p4:** EditorModule — enqueue_block_editor_assets hook ([ee076c7](https://github.com/niklas-joh/wp-ai-mind/commit/ee076c76076a4dd6426fdd06c39c7227279018dc))
* **p4:** Gutenberg sidebar — MiniChat, BlockActions, SeoPanel ([64df5e1](https://github.com/niklas-joh/wp-ai-mind/commit/64df5e14ec6c74c01a4052eae22bc40f3a38c377))
* **p5:** Generator Wizard UI + Frontend Chat Widget ([7599f03](https://github.com/niklas-joh/wp-ai-mind/commit/7599f03216b7921b45f828cd532e2c13fab875e9))
* **p5:** GeneratorModule (POST /generate → draft post) + FrontendWidgetModule (shortcode) ([fe5e482](https://github.com/niklas-joh/wp-ai-mind/commit/fe5e4826da72909006490435e2cf2753f79519d6))
* **p5:** GeneratorPage admin render + wire Generator submenu ([ebe6922](https://github.com/niklas-joh/wp-ai-mind/commit/ebe6922f13c8898a8997cd5fb5b1db262b63ff8f))
* **p6:** Usage Dashboard UI + WP.org build script ([787453f](https://github.com/niklas-joh/wp-ai-mind/commit/787453face99d028413052a047342193bec4a5bd))
* **p6:** UsageModule (GET /usage stats) + UsagePage + Freemius-ready ProGate ([9df7ab4](https://github.com/niklas-joh/wp-ai-mind/commit/9df7ab4841c9e9334daef802742d7b6e7d2f64fb))
* **providers:** add tool call support to CompletionRequest/Response and all providers ([440de18](https://github.com/niklas-joh/wp-ai-mind/commit/440de18868aab707f6f05636308317090e4bd53e))
* register wpaim_seo_status REST field, add admin page render callbacks ([bdc7ccf](https://github.com/niklas-joh/wp-ai-mind/commit/bdc7ccfb64e015cdc22fa9a31863806ee28ec777))
* SEO and Images admin pages (revised) ([#24](https://github.com/niklas-joh/wp-ai-mind/issues/24)) ([00f2064](https://github.com/niklas-joh/wp-ai-mind/commit/00f2064ac4c0cb83d83a3238ce9a75d253740e75))
* **settings:** add 'Run setup again' to Settings page ([20a795f](https://github.com/niklas-joh/wp-ai-mind/commit/20a795f9e57c89cff5003546a64be601b3e7d7d6))
* **settings:** check env vars before DB for provider API keys ([92bea43](https://github.com/niklas-joh/wp-ai-mind/commit/92bea436e3282bc24f92bf87ec9c8b2302bfb8e6))
* **settings:** check env vars before DB for provider API keys ([#107](https://github.com/niklas-joh/wp-ai-mind/issues/107)) ([cba75d9](https://github.com/niklas-joh/wp-ai-mind/commit/cba75d94f4dfd0ba0cf86019d09ab415363881f8))
* **tools:** add ToolDefinition, ToolRegistry, ToolExecutor with admin post-type setting ([570db1f](https://github.com/niklas-joh/wp-ai-mind/commit/570db1f3804d13b52adc36e68f2a5e4401b58877))
* **tools:** tool-call loop in ChatRestController + allowed post types in settings ([d4dc65f](https://github.com/niklas-joh/wp-ai-mind/commit/d4dc65f0fe5ed7726648eb9f7d672d6904b17eb1))
* **workflows:** automate end-to-end code review issue lifecycle ([#32](https://github.com/niklas-joh/wp-ai-mind/issues/32)) ([b0b51f8](https://github.com/niklas-joh/wp-ai-mind/commit/b0b51f8bf8ba094cf152af175e32468aaba911a7))
* **workflows:** feature-grouping and automated release system ([6692731](https://github.com/niklas-joh/wp-ai-mind/commit/6692731924f6d20e4be12f76334638886090771d))
* **workflows:** feature-grouping and automated release system ([#78](https://github.com/niklas-joh/wp-ai-mind/issues/78)) ([4fe3828](https://github.com/niklas-joh/wp-ai-mind/commit/4fe38284cb7950d38b7a24cff42cca0f7648f9a6))
* **wp-ai-mind:** add CHANGELOG.md and i18n .pot file (M5, H4) ([444b67f](https://github.com/niklas-joh/wp-ai-mind/commit/444b67faa9641895b36f17ec7d1e977e0433d876))
* **wp-ai-mind:** add GDPR activation notice and fix orphaned option (H3, M4) ([3920026](https://github.com/niklas-joh/wp-ai-mind/commit/39200266e1c0f390720f429ab703c1986b09ac50))
* **wp-ai-mind:** integrate Freemius SDK bootstrap for Pro licensing (C3) ([52bee75](https://github.com/niklas-joh/wp-ai-mind/commit/52bee7522c13ba08405ff565315a76f0ab9eb4ee))

# Changelog

All notable changes to WP AI Mind are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning: [Semantic Versioning](https://semver.org/).

## [0.3.0-beta.1] — 2026-03-27

### Changed
- Migrated admin settings form controls to `@wordpress/components`: `TextareaControl` (VoiceTab), `TextControl` + `SelectControl` (ProvidersTab), `CheckboxControl` + `ToggleControl` (FeaturesTab)
- Replaced custom tab navigation and notice `<span>` elements in `SettingsApp` with `TabPanel` and `Notice` from `@wordpress/components`
- Migrated generator inputs and selects to `TextControl` and `SelectControl`; generator and "Generate another" buttons to `Button` (`@wordpress/components`)
- Migrated QuickActions buttons to `Button variant="tertiary"` with dark-theme CSS overrides
- Migrated Composer send button to `Button variant="primary"`
- Replaced custom table className in `UsageDashboard` with WP native `widefat fixed striped`

### Fixed
- `FeaturesTab` was importing `useState` from `'react'` instead of `'@wordpress/element'`

### Accessibility
- Added `aria-label` attributes to both `<select>` elements in `ModelSelector`

### Removed
- Replaced custom CSS rules for `.wpaim-settings-notice`, `.wpaim-settings-tabs`, `.wpaim-settings-tab`, `.wpaim-settings-content`, `.wpaim-input`, `.wpaim-textarea`, `.wpaim-btn`, `.wpaim-btn--primary`, `.wpaim-btn--ghost`, `.wpaim-field-label` from `admin.css`
- Replaced custom input, select, and button CSS from `generator.css`
- Replaced custom table CSS from `usage.css`

## [0.2.0] — 2026-03-25

### Added
- Dedicated GitHub repository (`niklas-joh/wp-ai-mind`) extracted from blog monorepo
- GitHub Actions CI pipeline (PHPCS, PHPUnit, JS/CSS lint) on push/PR to main and develop
- GitHub Actions Release workflow — builds WP.org zip and creates GitHub Release on semver tags
- `RELEASING.md` — semver convention and release checklist
- Regenerated `languages/wp-ai-mind.pot` from source (was a stub)

### Fixed
- `bin/build-wporg.sh` now includes production `vendor/` in the distribution zip (Freemius SDK was missing from built zip)

## [0.1.0] — 2026-03-25

### Added
- Initial release.
- Chat assistant with multi-provider support (Claude, OpenAI, Gemini, Ollama).
- Content generator module with tone and length controls.
- SEO metadata generator (Pro).
- Image generation module (Pro).
- Usage dashboard with per-provider token tracking.
- Frontend chat widget via `[wp_ai_mind_chat]` shortcode.
- Gutenberg sidebar integration.
- Tool-calling support for post creation and editing.
- REST API v1 with capability-gated endpoints.
