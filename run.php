<?php

/**
 * Louvain-like community detection (simplified)
 * Input: array of nodes and adjacency list of dependencies.
 *
 * Example input format:
 *
 * $graph = [
 *     'A' => ['B', 'C'],
 *     'B' => ['A', 'D'],
 *     'C' => ['A'],
 *     'D' => ['B'],
 *     'E' => ['F'],
 *     'F' => ['E'],
 * ];
 */

$graph = [
    'ClassA' => ['ClassB', 'ClassC'],
    'ClassB' => ['ClassA', 'ClassD'],
    'ClassC' => ['ClassA'],
    'ClassD' => ['ClassB'],
    'ClassE' => ['ClassF'],
    'ClassF' => ['ClassE'],
];

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
foreach ($result as $comm => $nodes) {
    echo "- Communauté $comm : " . implode(", ", $nodes) . "\n";
}

