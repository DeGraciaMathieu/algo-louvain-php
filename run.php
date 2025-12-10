<?php

/**
 * Louvain-like community detection (simplified)
 * Input: JSON file from dependency.php analysis
 *
 * Usage: php run.php [analysis_file.json]
 * If no file is provided, looks for the most recent analysis_*.json file
 */

// Déterminer le fichier d'analyse à utiliser
$analysisFile = $argv[1] ?? null;

if ($analysisFile === null) {
    // Chercher le fichier d'analyse le plus récent
    $files = glob('analysis_*.json');
    if (empty($files)) {
        die("Erreur: Aucun fichier d'analyse trouvé. Exécutez d'abord dependency.php\n");
    }
    // Trier par date de modification (le plus récent en premier)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $analysisFile = $files[0];
    echo "Utilisation du fichier d'analyse: $analysisFile\n\n";
}

if (!file_exists($analysisFile)) {
    die("Erreur: Le fichier '$analysisFile' n'existe pas.\n");
}

// Charger les données d'analyse
$analysisData = json_decode(file_get_contents($analysisFile), true);

if ($analysisData === null) {
    die("Erreur: Impossible de parser le fichier JSON.\n");
}

// Construire le graphe à partir des relations
// Format: chaque dossier est un nœud, et on crée des arêtes bidirectionnelles
// basées sur les relations de dépendances
$graph = [];

// Initialiser tous les nœuds (dossiers)
foreach ($analysisData as $folder => $data) {
    if (!isset($graph[$folder])) {
        $graph[$folder] = [];
    }
}

// Construire le graphe bidirectionnel
// Si A a une relation vers B, alors A -> B et B -> A dans le graphe
foreach ($analysisData as $folder => $data) {
    if (isset($data['relations']) && is_array($data['relations'])) {
        foreach ($data['relations'] as $targetFolder) {
            // Ajouter la relation A -> B
            if (!in_array($targetFolder, $graph[$folder])) {
                $graph[$folder][] = $targetFolder;
            }
            
            // Ajouter la relation inverse B -> A (pour un graphe non-orienté)
            if (!isset($graph[$targetFolder])) {
                $graph[$targetFolder] = [];
            }
            if (!in_array($folder, $graph[$targetFolder])) {
                $graph[$targetFolder][] = $folder;
            }
        }
    }
}

$communities = [];
foreach ($graph as $node => $_) {
    $communities[$node] = $node;
}

// Calcule le nombre total d’arêtes
function totalEdges($graph) {
    $sum = 0;
    foreach ($graph as $deps) {
        $sum += count($deps);
    }
    return $sum / 2;
}

// Calcul des degrés
function degree($node, $graph) {
    return count($graph[$node]);
}

$m = totalEdges($graph);

function deltaModularity($node, $community, $communities, $graph, $m) {
    $k_i = degree($node, $graph);

    $sum_in = 0;
    $sum_tot = 0;

    foreach ($graph as $n => $deps) {
        if ($communities[$n] === $community) {
            $sum_tot += degree($n, $graph);
            if (in_array($node, $deps)) {
                $sum_in += 1;
            }
        }
    }

    return ($sum_in / (2 * $m)) - (($k_i * $sum_tot) / (4 * $m * $m));
}

$changed = true;

while ($changed) {
    $changed = false;

    foreach ($graph as $node => $deps) {
        $currentCommunity = $communities[$node];
        $bestCommunity = $currentCommunity;
        $bestDelta = 0;

        // Tester rejoindre la communauté d’un voisin
        foreach ($deps as $neighbor) {
            $targetCommunity = $communities[$neighbor];
            $delta = deltaModularity($node, $targetCommunity, $communities, $graph, $m);

            if ($delta > $bestDelta) {
                $bestDelta = $delta;
                $bestCommunity = $targetCommunity;
            }
        }

        if ($bestCommunity !== $currentCommunity) {
            $communities[$node] = $bestCommunity;
            $changed = true;
        }
    }
}

$result = [];
foreach ($communities as $node => $comm) {
    $result[$comm][] = $node;
}

echo "Communautés détectées :\n\n";
$communityIndex = 1;
$communityLabels = []; // Mapping communauté -> label
foreach ($result as $comm => $nodes) {
    $communityLabels[$comm] = $communityIndex;
    echo "Communauté #$communityIndex (représentant: $comm) :\n";
    foreach ($nodes as $node) {
        echo "  - $node\n";
    }
    echo "\n";
    $communityIndex++;
}

// Analyser les dépendances entre communautés
echo "═══════════════════════════════════════════════════════════════\n";
echo "Dépendances entre communautés :\n\n";

$communityDependencies = []; // [comm1][comm2] => nombre de liens

// Initialiser la matrice de dépendances
foreach ($result as $comm1 => $nodes1) {
    $communityDependencies[$comm1] = [];
    foreach ($result as $comm2 => $nodes2) {
        $communityDependencies[$comm1][$comm2] = 0;
    }
}

// Compter les liens entre communautés
foreach ($graph as $node => $deps) {
    $nodeCommunity = $communities[$node];
    
    foreach ($deps as $neighbor) {
        $neighborCommunity = $communities[$neighbor];
        
        // Si les deux nœuds sont dans des communautés différentes
        if ($nodeCommunity !== $neighborCommunity) {
            $communityDependencies[$nodeCommunity][$neighborCommunity]++;
        }
    }
}

// Afficher les dépendances
$hasDependencies = false;
foreach ($communityDependencies as $comm1 => $deps) {
    $comm1Label = $communityLabels[$comm1];
    $hasCommDeps = false;
    
    foreach ($deps as $comm2 => $count) {
        if ($comm1 !== $comm2 && $count > 0) {
            if (!$hasCommDeps) {
                echo "Communauté #$comm1Label ($comm1) dépend de :\n";
                $hasCommDeps = true;
                $hasDependencies = true;
            }
            $comm2Label = $communityLabels[$comm2];
            echo "  → Communauté #$comm2Label ($comm2) : $count lien(s)\n";
        }
    }
    
    if ($hasCommDeps) {
        echo "\n";
    }
}

if (!$hasDependencies) {
    echo "Aucune dépendance entre communautés détectée.\n\n";
}

// Créer un graphe simplifié des communautés
$communityGraph = [];
foreach ($communityDependencies as $comm1 => $deps) {
    $communityGraph[$comm1] = [];
    foreach ($deps as $comm2 => $count) {
        if ($comm1 !== $comm2 && $count > 0) {
            $communityGraph[$comm1][] = $comm2;
        }
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "Graphe des communautés (format simplifié) :\n\n";
foreach ($communityGraph as $comm => $targetCommunities) {
    if (!empty($targetCommunities)) {
        $commLabel = $communityLabels[$comm];
        $targetLabels = array_map(function($c) use ($communityLabels) {
            return '#' . $communityLabels[$c];
        }, $targetCommunities);
        echo "Communauté #$commLabel → " . implode(', ', $targetLabels) . "\n";
    }
}
echo "\n";

// Sauvegarde des résultats en JSON
$jsonResult = [
    'graph' => $graph,
    'communities' => $communities,
    'communities_by_group' => $result,
    'community_dependencies' => $communityDependencies,
    'community_graph' => $communityGraph,
    'total_edges' => $m,
    'total_nodes' => count($graph)
];

$jsonFile = 'results.json';
file_put_contents($jsonFile, json_encode($jsonResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nRésultats sauvegardés dans $jsonFile\n";

