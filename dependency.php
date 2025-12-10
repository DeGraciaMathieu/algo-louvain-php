
<?php

class GitHubRepoAnalyzer
{
    private string $tempDir;
    private array $results = [];
    private array $namespaceMapping = []; // Mapping namespace → dossier

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . '/github_repo_' . uniqid();
    }

    /**
     * Télécharge et analyse un dépôt GitHub
     */
    public function analyzeRepository(string $source, ?string $rootDir = null): array
    {
        try {
            $this->tempDir = $this->prepareSource($source, sys_get_temp_dir() . '/github_repo_' . uniqid());

            // Si un dossier racine est spécifié, l'utiliser comme point de départ
            $startPath = $rootDir ? $this->tempDir . '/' . $rootDir : $this->tempDir;

            if ($rootDir && !is_dir($startPath)) {
                throw new Exception("Le dossier racine '$rootDir' n'existe pas dans le projet");
            }

            // Construire le mapping des namespaces avant l'analyse
            $this->buildNamespaceMapping($startPath, $startPath);

            $this->analyzeDirectory($startPath, '', $this->tempDir);

            // Calculer les métriques de couplage après avoir analysé tous les dossiers
            $this->calculateCouplingMetrics();

            $this->cleanup();

            return $this->results;
        } catch (Exception $e) {
            $this->cleanup();
            throw $e;
        }
    }

    /**
     * Construit un mapping des namespaces vers les dossiers
     */
    private function buildNamespaceMapping(string $basePath, string $currentPath): void
    {
        if (!is_dir($currentPath)) {
            return;
        }

        $phpFiles = $this->findPhpFiles($currentPath);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Extraire le namespace du fichier
            preg_match('/namespace\s+([\w\\\\]+);/', $content, $matches);
            if (isset($matches[1])) {
                $namespace = $matches[1];
                $relativePath = str_replace($basePath . '/', '', dirname($file));

                // Stocker le mapping
                $this->namespaceMapping[$namespace] = $relativePath;

                // Stocker aussi les sous-namespaces
                $namespaceParts = explode('\\', $namespace);
                for ($i = 1; $i <= count($namespaceParts); $i++) {
                    $partialNamespace = implode('\\', array_slice($namespaceParts, 0, $i));
                    if (!isset($this->namespaceMapping[$partialNamespace])) {
                        $this->namespaceMapping[$partialNamespace] = $relativePath;
                    }
                }
            }
        }

        // Analyser récursivement les sous-dossiers
        $subdirs = glob($currentPath . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $this->buildNamespaceMapping($basePath, $subdir);
        }
    }

    /**
     * Prépare la source (clone Git ou dossier local)
     */
    private function prepareSource($source, $outputDir = './temp')
    {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            // C'est une URL GitHub
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $repoName = basename(parse_url($source, PHP_URL_PATH), '.git');
            $targetDir = $outputDir . '/' . $repoName;

            if (is_dir($targetDir)) {
                echo "Dossier $targetDir existe déjà, suppression...\n";
                $this->removeDirectory($targetDir);
            }

            $command = "git clone $source $targetDir";
            echo "Clonage: $command\n";
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception("Échec du clonage: " . implode("\n", $output));
            }

            return $targetDir;
        } else {
            // C'est un dossier local
            if (!is_dir($source)) {
                throw new Exception("Le dossier $source n'existe pas");
            }
            return $source;
        }
    }

    /**
     * Supprime récursivement un répertoire
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Analyse récursivement un répertoire
     */
    private function analyzeDirectory(string $basePath, string $relativePath = '', string $originalBasePath = null): void
    {
        $fullPath = $basePath . ($relativePath ? '/' . $relativePath : '');
        $originalBasePath = $originalBasePath ?: $basePath;

        if (!is_dir($fullPath)) {
            return;
        }

        $phpFiles = $this->findPhpFiles($fullPath);

        // Analyser les fichiers PHP du dossier courant s'il y en a
        if (!empty($phpFiles)) {
            $folderKey = $relativePath ?: '/';
            $this->results[$folderKey] = [
                'classes' => 0,
                'interfaces' => 0,
                'abstracts' => 0,
                'total' => 0,
                'file_count' => 0,
                'relations' => [],
                'metrics' => [
                    'efferent_coupling' => 0,
                    'afferent_coupling' => 0,
                    'instability' => 0.0,
                    'loc_total' => 0,
                    'ccn_total' => 0,
                ]
            ];

            foreach ($phpFiles as $file) {
                $this->analyzePhpFile($file, $folderKey, $originalBasePath);
            }

            // Finaliser les métriques dépendantes du nombre de fichiers
            $this->finalizeFolderMetrics($folderKey);
        }

        // Toujours analyser les sous-dossiers, même s'il y a des fichiers PHP
        $subdirs = glob($fullPath . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $subdirName = basename($subdir);
            $newRelativePath = $relativePath ? $relativePath . '/' . $subdirName : $subdirName;
            $this->analyzeDirectory($basePath, $newRelativePath, $originalBasePath);
        }
    }

    /**
     * Trouve tous les fichiers PHP dans un dossier
     */
    private function findPhpFiles(string $directory): array
    {
        return glob($directory . '/*.php') ?: [];
    }

    /**
     * Analyse un fichier PHP
     */
    private function analyzePhpFile(string $filePath, string $folderKey, string $basePath): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        // Comptage LOC et CCN par fichier
        $sanitized = $this->stripCommentsAndStrings($content);
        $loc = $this->countEffectiveLinesOfCode($sanitized);
        $ccn = $this->computeCyclomaticComplexity($sanitized);

        $this->results[$folderKey]['metrics']['loc_total'] += $loc;
        $this->results[$folderKey]['metrics']['ccn_total'] += $ccn;
        $this->results[$folderKey]['file_count']++;

        // Analyser les types de classes
        $this->analyzeClassTypes($content, $folderKey);

        // Analyser les relations (use, extends, implements)
        $this->analyzeRelations($content, $folderKey, $basePath);
    }

    /**
     * Analyse les types de classes dans le contenu
     */
    private function analyzeClassTypes(string $content, string $folderKey): void
    {
        // Compter les classes
        preg_match_all('/^\s*class\s+\w+/m', $content, $classes);
        $this->results[$folderKey]['classes'] += count($classes[0]);

        // Compter les interfaces
        preg_match_all('/^\s*interface\s+\w+/m', $content, $interfaces);
        $this->results[$folderKey]['interfaces'] += count($interfaces[0]);

        // Compter les classes abstraites
        preg_match_all('/^\s*abstract\s+class\s+\w+/m', $content, $abstracts);
        $this->results[$folderKey]['abstracts'] += count($abstracts[0]);

        // Total
        $this->results[$folderKey]['total'] =
            $this->results[$folderKey]['classes'] +
            $this->results[$folderKey]['interfaces'] +
            $this->results[$folderKey]['abstracts'];
    }

    /**
     * Analyse les relations entre dossiers
     */
    private function analyzeRelations(string $content, string $folderKey, string $basePath): void
    {
        // Extraire le namespace du fichier courant
        preg_match('/namespace\s+([\w\\\\]+);/', $content, $currentNamespace);
        $currentNs = $currentNamespace[1] ?? '';

        // Trouver toutes les déclarations use
        preg_match_all('/use\s+([\w\\\\]+)(?:\s+as\s+\w+)?;/', $content, $uses);

        foreach ($uses[1] as $useStatement) {
            $targetFolder = $this->namespaceToFolder($useStatement, $basePath);

            if ($targetFolder && $targetFolder !== $folderKey && $targetFolder !== '.' && $targetFolder !== '/') {
                if (!in_array($targetFolder, $this->results[$folderKey]['relations'])) {
                    $this->results[$folderKey]['relations'][] = $targetFolder;
                }
            }
        }

        // Analyser extends et implements
        preg_match_all('/(?:extends|implements)\s+([\w\\\\,\s]+)/', $content, $heritage);

        foreach ($heritage[1] as $heritageClasses) {
            $classes = preg_split('/[,\s]+/', trim($heritageClasses));

            foreach ($classes as $className) {
                $className = trim($className);
                if (empty($className)) continue;

                // Si pas de namespace complet, essayer de deviner avec le namespace courant
                if (!str_contains($className, '\\') && $currentNs) {
                    $fullClassName = $currentNs . '\\' . $className;
                } else {
                    $fullClassName = $className;
                }

                $targetFolder = $this->namespaceToFolder($fullClassName, $basePath);

                if ($targetFolder && $targetFolder !== $folderKey && $targetFolder !== '.' && $targetFolder !== '/') {
                    if (!in_array($targetFolder, $this->results[$folderKey]['relations'])) {
                        $this->results[$folderKey]['relations'][] = $targetFolder;
                    }
                }
            }
        }
    }

    /**
     * Convertit un namespace en chemin de dossier
     */
    private function namespaceToFolder(string $namespace, string $basePath): ?string
    {
        // Utiliser le mapping des namespaces si disponible
        if (isset($this->namespaceMapping[$namespace])) {
            return $this->namespaceMapping[$namespace];
        }

        // Essayer de trouver une correspondance partielle
        $namespaceParts = explode('\\', $namespace);
        for ($i = count($namespaceParts); $i > 0; $i--) {
            $partialNamespace = implode('\\', array_slice($namespaceParts, 0, $i));
            if (isset($this->namespaceMapping[$partialNamespace])) {
                return $this->namespaceMapping[$partialNamespace];
            }
        }

        // Fallback : essayer de convertir le namespace en chemin
        $namespace = ltrim($namespace, '\\');
        $path = str_replace('\\', '/', $namespace);

        // Essayer de trouver le dossier correspondant
        $possiblePaths = [
            $path,
            'src/' . $path,
            'lib/' . $path,
            'app/' . $path,
        ];

        foreach ($possiblePaths as $possiblePath) {
            $fullPath = $basePath . '/' . dirname($possiblePath);

            if (is_dir($fullPath)) {
                $relativePath = $this->getRelativePath($basePath, $fullPath);

                // Éviter les chemins problématiques
                if ($relativePath && $relativePath !== '.' && $relativePath !== '/' && $relativePath !== '') {
                    return $relativePath;
                }
            }
        }

        return null;
    }

    /**
     * Obtient le chemin relatif
     */
    private function getRelativePath(string $basePath, string $fullPath): string
    {
        $basePath = rtrim($basePath, '/');
        $relativePath = str_replace($basePath . '/', '', $fullPath);

        return $relativePath === $basePath ? '/' : $relativePath;
    }

    /**
     * Calcule les métriques de couplage pour tous les dossiers
     */
    private function calculateCouplingMetrics(): void
    {
        // Calculer l'efferent coupling (dépendances sortantes)
        foreach ($this->results as $folderKey => $folderData) {
            $this->results[$folderKey]['metrics']['efferent_coupling'] = count($folderData['relations']);
        }

        // Calculer l'afferent coupling (dépendances entrantes)
        foreach ($this->results as $folderKey => $folderData) {
            $afferentCount = 0;

            foreach ($this->results as $otherFolderKey => $otherFolderData) {
                if ($otherFolderKey !== $folderKey) {
                    if (in_array($folderKey, $otherFolderData['relations'])) {
                        $afferentCount++;
                    }
                }
            }

            $this->results[$folderKey]['metrics']['afferent_coupling'] = $afferentCount;
        }

        // Calculer l'instabilité
        foreach ($this->results as $folderKey => $folderData) {
            $efferent = $folderData['metrics']['efferent_coupling'];
            $afferent = $folderData['metrics']['afferent_coupling'];
            $total = $efferent + $afferent;

            if ($total > 0) {
                $this->results[$folderKey]['metrics']['instability'] = round($efferent / $total, 3);
            } else {
                $this->results[$folderKey]['metrics']['instability'] = 0.0;
            }
        }
    }

    /**
     * Supprime commentaires et chaînes pour simplifier l'analyse lexicale
     */
    private function stripCommentsAndStrings(string $code): string
    {
        // Supprimer les commentaires multi-lignes
        $code = preg_replace('/\/\*.*?\*\//s', '', $code);
        // Supprimer les commentaires mono-ligne // et #
        $code = preg_replace('/\/\/.*$/m', '', $code);
        $code = preg_replace('/#.*$/m', '', $code);
        // Supprimer les chaînes simples et doubles
        $code = preg_replace("/'(?:\\\\.|[^'\\\\])*'/s", "''", $code);
        $code = preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '""', $code);
        return $code ?? '';
    }

    /**
     * Compte les lignes de code effectives (non vides) après nettoyage
     */
    private function countEffectiveLinesOfCode(string $sanitizedCode): int
    {
        $lines = preg_split('/\R/', $sanitizedCode) ?: [];
        $count = 0;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Calcule une approximation du CCN pour un fichier
     */
    private function computeCyclomaticComplexity(string $sanitizedCode): int
    {
        $ccn = 1; // Base

        $patterns = [
            '/\bif\s*\(/i',
            '/\belseif\s*\(/i',
            '/\bfor\s*\(/i',
            '/\bforeach\s*\(/i',
            '/\bwhile\s*\(/i',
            '/\bcase\b/i',
            '/\bcatch\s*\(/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $sanitizedCode, $m)) {
                $ccn += count($m[0]);
            }
        }

        // Opérateurs logiques && et ||
        if (preg_match_all('/&&/', $sanitizedCode, $m)) {
            $ccn += count($m[0]);
        }
        if (preg_match_all('/\|\|/', $sanitizedCode, $m)) {
            $ccn += count($m[0]);
        }

        // Ternaires ?:
        $noSpecial = $sanitizedCode;
        $noSpecial = preg_replace('/\?\?/', '', $noSpecial);   // null coalescing
        $noSpecial = preg_replace('/\?->/', '', $noSpecial);    // nullsafe operator
        $noSpecial = preg_replace('/<\?php/i', '', $noSpecial); // open tag
        $noSpecial = preg_replace('/\?>/', '', $noSpecial);     // close tag
        if (preg_match_all('/\?/', $noSpecial, $m)) {
            $ccn += count($m[0]);
        }

        return $ccn;
    }

    /**
     * Finalise les métriques par dossier dépendantes du nombre de fichiers
     */
    private function finalizeFolderMetrics(string $folderKey): void
    {
        // $fileCount = $this->results[$folderKey]['file_count'] ?? 0;
        // if ($fileCount > 0) {
        //     $this->results[$folderKey]['metrics']['loc_avg'] = round($this->results[$folderKey]['metrics']['loc_total'] / $fileCount, 2);
        //     $this->results[$folderKey]['metrics']['ccn_avg'] = round($this->results[$folderKey]['metrics']['ccn_total'] / $fileCount, 2);
        // } else {
        //     $this->results[$folderKey]['metrics']['loc_avg'] = 0.0;
        //     $this->results[$folderKey]['metrics']['ccn_avg'] = 0.0;
        // }
    }

    /**
     * Nettoie les fichiers temporaires
     */
    private function cleanup(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }
}

// Utilisation du script
function analyzeGitHubRepo(string $source, ?string $rootDir = null): string
{
    try {
        $analyzer = new GitHubRepoAnalyzer();
        $results = $analyzer->analyzeRepository($source, $rootDir);

        // Enregistrer les résultats dans un fichier JSON
        $timestamp = date('Y-m-d_H-i-s');
        $repoName = basename(parse_url($source, PHP_URL_PATH), '.git') ?: 'local_project';
        $rootDirSuffix = $rootDir ? "_root_{$rootDir}" : '';
        $filename = "analysis_{$repoName}{$rootDirSuffix}_{$timestamp}.json";

        $jsonData = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($filename, $jsonData);

        echo "Analyse sauvegardée dans: $filename\n";

        return $jsonData;
    } catch (Exception $e) {
        $errorData = json_encode([
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT);

        // Enregistrer aussi l'erreur dans un fichier
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "error_analysis_{$timestamp}.json";
        file_put_contents($filename, $errorData);

        echo "Erreur sauvegardée dans: $filename\n";

        return $errorData;
    }
}

// Exemple d'utilisation
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php script.php <github_url_or_local_path> [root_directory]\n";
        echo "Exemple: php script.php https://github.com/symfony/symfony\n";
        echo "Exemple: php script.php /path/to/local/project\n";
        echo "Exemple: php script.php https://github.com/symfony/symfony src\n";
        echo "Exemple: php script.php /path/to/local/project app\n";
        exit(1);
    }

    $source = $argv[1];
    $rootDir = $argv[2] ?? null;

    echo "Analyse de: $source\n";
    if ($rootDir) {
        echo "Dossier racine: $rootDir\n";
    }
    echo "Cela peut prendre quelques minutes...\n\n";

    $result = analyzeGitHubRepo($source, $rootDir);
    echo $result . "\n";
} else {
    // Utilisation via navigateur web
    $source = $_GET['source'] ?? $_GET['url'] ?? '';
    $rootDir = $_GET['root'] ?? null;

    if (empty($source)) {
        echo json_encode([
            'error' => 'Veuillez fournir une URL GitHub ou un chemin local via le paramètre ?source=... ou ?url=...'
        ]);
        exit;
    }

    header('Content-Type: application/json');
    echo analyzeGitHubRepo($source, $rootDir);
}
