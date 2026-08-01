'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
const packageManifest = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const phpBuild = fs.readFileSync(path.join(root, 'lib', 'Build.php'), 'utf8');

const capture = (text, expression, label) => {
	const match = text.match(expression);
	assert.ok(match, `${label} is missing.`);
	return match[1];
};

let assertions = 0;
const same = (expected, actual, message) => {
	assertions++;
	assert.strictEqual(actual, expected, message);
};
const ok = (condition, message) => {
	assertions++;
	assert.ok(condition, message);
};

const browserAssets = manifest.assets && Array.isArray(manifest.assets.js)
	? manifest.assets.js.filter((asset) => asset.startsWith('capacity-planning') && asset.endsWith('.js'))
	: [];
same(1, browserAssets.length, 'manifest.json must register exactly one Capacity Planning browser bundle.');
const browserAsset = browserAssets[0];
const browser = fs.readFileSync(path.join(root, 'assets', 'js', browserAsset), 'utf8');

// Zabbix links whatever the manifest names. An entry with no file on disk 404s
// silently and renders the report unstyled, so the stylesheet needs the same
// gate as the bundle.
const styleAssets = manifest.assets && Array.isArray(manifest.assets.css)
	? manifest.assets.css.filter((asset) => asset.startsWith('capacity-planning') && asset.endsWith('.css'))
	: [];
same(1, styleAssets.length, 'manifest.json must register exactly one Capacity Planning stylesheet.');
ok(fs.existsSync(path.join(root, 'assets', 'css', styleAssets[0])),
	`manifest.json registers assets/css/${styleAssets[0]}, which does not exist on disk.`);

const phpVersion = capture(phpBuild, /public const VERSION = '([^']+)'/, 'PHP module version');
const phpBuildId = capture(phpBuild, /public const ID = '([^']+)'/, 'PHP build ID');
const jsVersion = capture(browser, /const MODULE_VERSION = '([^']+)'/, 'browser module version');
const jsBuildId = capture(browser, /const CLIENT_BUILD_ID = '([^']+)'/, 'browser build ID');

same(manifest.version, phpVersion, 'manifest.json and PHP must expose the same module version.');
same(manifest.version, packageManifest.version, 'manifest.json and package.json must expose the same module version.');
same(phpVersion, jsVersion, 'PHP and browser bundles must expose the same module version.');
same(phpBuildId, jsBuildId, 'PHP and browser bundles must expose the same build ID.');
ok(phpBuildId.startsWith(`${phpVersion}-`), 'The build ID must be namespaced by the module version.');
same(`capacity-planning-${phpBuildId}.js`, browserAsset,
	'The browser asset filename must include the complete PHP build ID.');

// README step 1 of the upgrade checklist is the operator's pre-runtime defense
// against a stale deployment, so its identifiers have to rotate with the build.
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
ok(readme.includes(`Current release: **${phpVersion}** (build **${phpBuildId}**)`),
	`README.md must state the current release as ${phpVersion} (build ${phpBuildId}).`);
ok(readme.includes(`assets/js/capacity-planning-${phpBuildId}.js`),
	`README.md must reference the registered bundle capacity-planning-${phpBuildId}.js.`);
ok(readme.includes(`reports version **${phpVersion}**`),
	`README.md upgrade checklist must state manifest version ${phpVersion}.`);
ok(readme.includes(`reports build **${phpBuildId}**`),
	`README.md upgrade checklist must state build ${phpBuildId}.`);

console.log(`BuildConsistencyTest: ${assertions} assertions passed.`);
