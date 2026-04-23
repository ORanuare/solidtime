import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

async function collectModuleAssetsPaths(modulesPath) {
    return await getExportedModulesArrayAttributes(modulesPath, 'paths');
}

async function collectModulePlugins(modulesPath) {
    return await getExportedModulesArrayAttributes(modulesPath, 'plugins');
}

async function getExportedModulesArrayAttributes(modulesPath, attribute) {
    const result = [];
    const modulesDir = path.join(__dirname, modulesPath);
    const moduleStatusesPath = path.join(__dirname, 'modules_statuses.json');

    let moduleStatuses = {};
    try {
        const moduleStatusesContent = await fs.readFile(moduleStatusesPath, 'utf-8');
        moduleStatuses = JSON.parse(moduleStatusesContent);
    } catch (error) {
        if (error?.code !== 'ENOENT') {
            console.error(`Error reading module statuses: ${error}`);
        }
    }

    let moduleDirectories = [];
    try {
        moduleDirectories = await fs.readdir(modulesDir);
    } catch (error) {
        if (error?.code !== 'ENOENT') {
            console.error(`Error reading extensions directory: ${error}`);
        }
        return result;
    }

    for (const moduleDir of moduleDirectories) {
        if (moduleDir === '.DS_Store') {
            continue;
        }

        if (moduleStatuses[moduleDir] !== true) {
            continue;
        }

        const viteConfigPath = path.join(modulesDir, moduleDir, 'vite.config.js');
        try {
            const stat = await fs.stat(viteConfigPath);

            if (!stat.isFile()) {
                continue;
            }

            const moduleConfig = await import(viteConfigPath);

            if (moduleConfig[attribute] && Array.isArray(moduleConfig[attribute])) {
                result.push(...moduleConfig[attribute]);
            }
        } catch (error) {
            console.error(`Error loading extension Vite config for ${moduleDir}: ${error}`);
        }
    }

    return result;
}

export { collectModuleAssetsPaths, collectModulePlugins };
