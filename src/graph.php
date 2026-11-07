<?php
/**
 * Editorial VCS — Entity Relationship Graph
 * Interactive D3.js visualization of lore entry connections.
 * Optional feature — requires both 'lore_bible' and 'relationship_graph' enabled.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

session_start();

// Check feature flags
if (!feature_enabled('lore_bible') || !feature_enabled('relationship_graph')) {
    header('Location: index.php');
    exit;
}

// Require login
if (!auth_current_user()) {
    header('Location: index.php?page=login');
    exit;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Load entities from lore_entries
$char_entries = db()->query("
    SELECT id, name, slug, title, category, faction, aliases, content
    FROM lore_entries
    WHERE category IN ('character', 'entity')
    ORDER BY name
")->fetchAll();

// Build character array with search names
$characters = [];
$name_lookup = [];

foreach ($char_entries as $idx => $entry) {
    $search_names = [$entry['name']];
    if ($entry['aliases']) {
        foreach (explode(',', $entry['aliases']) as $alias) {
            $a = trim($alias);
            if ($a !== '' && strlen($a) > 2) $search_names[] = $a;
        }
    }

    $characters[$idx] = [
        'name' => $entry['name'],
        'slug' => $entry['slug'],
        'title' => $entry['title'] ?? '',
        'faction' => $entry['faction'] ?? 'Unknown',
        'bio' => mb_substr($entry['content'] ?? '', 0, 1000),
        'search_names' => $search_names,
        'appearances' => [],
        'timeline' => [],
    ];

    foreach ($search_names as $sn) {
        $lower = strtolower($sn);
        $name_lookup[$lower] = $idx;
    }
    $parts = explode(' ', $entry['name']);
    if (count($parts) > 1) {
        $last = end($parts);
        if (strlen($last) > 3) {
            $name_lookup[strtolower($last)] = $idx;
        }
    }
}

// Scan vault files for mentions
$all_files = db()->query("
    SELECT f.id AS file_id, f.filepath, p.name AS project_name, p.slug AS project_slug, r.content
    FROM files f
    JOIN projects p ON f.project_id = p.id
    JOIN revisions r ON r.file_id = f.id
    WHERE f.is_deleted = 0 AND f.filetype = 'text'
    AND r.id = (SELECT r2.id FROM revisions r2 WHERE r2.file_id = f.id ORDER BY r2.created_at DESC LIMIT 1)
")->fetchAll();

$file_chars = [];

foreach ($all_files as $fi => $file) {
    $content = $file['content'] ?? '';
    if (!$content) continue;

    $found_chars = [];
    $doc_label = $file['project_name'] . '/' . basename($file['filepath']);

    foreach ($characters as $ci => $char) {
        foreach ($char['search_names'] as $sn) {
            if (stripos($content, $sn) !== false) {
                $found_chars[] = $ci;
                if (!in_array($doc_label, $characters[$ci]['appearances'])) {
                    $characters[$ci]['appearances'][] = $doc_label;
                }
                break;
            }
        }
    }

    if (count($found_chars) > 1) {
        $file_chars[$fi] = [
            'chars' => $found_chars,
            'doc' => $doc_label,
        ];
    }
}

// Build edges from co-occurrence
$edges = [];

function add_edge(int $a, int $b, string $doc, string $type, array &$edges): void {
    if ($a === $b) return;
    $key = min($a, $b) . '-' . max($a, $b);
    if (!isset($edges[$key])) {
        $edges[$key] = ['source' => $a, 'target' => $b, 'docs' => [], 'types' => []];
    }
    if (!in_array($doc, $edges[$key]['docs'])) {
        $edges[$key]['docs'][] = $doc;
    }
    if (!in_array($type, $edges[$key]['types'])) {
        $edges[$key]['types'][] = $type;
    }
}

foreach ($file_chars as $fc) {
    $chars = $fc['chars'];
    for ($a = 0; $a < count($chars); $a++) {
        for ($b = $a + 1; $b < count($chars); $b++) {
            add_edge($chars[$a], $chars[$b], $fc['doc'], 'document', $edges);
        }
    }
}

// Prepare JSON for JS
$graph_data = [
    'nodes' => array_values(array_map(function($c, $i) {
        return [
            'id' => $i,
            'name' => $c['name'],
            'slug' => $c['slug'],
            'title' => $c['title'],
            'faction' => $c['faction'],
            'bio' => $c['bio'],
            'appearances' => count($c['appearances']),
            'timeline' => array_unique($c['timeline']),
            'docs' => $c['appearances'],
        ];
    }, $characters, array_keys($characters))),
    'edges' => array_values($edges),
    'timeline' => [],
];

$graph_json = json_encode($graph_data, JSON_UNESCAPED_UNICODE);
$app_title = app_title();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app_title) ?> — Entity Graph</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #0a0a0c;
    --bg-card: #111115;
    --bg-hover: #1a1a20;
    --border: #2a2a32;
    --border-light: #3a3a44;
    --text: #c8c8d0;
    --text-dim: #6a6a78;
    --text-bright: #e8e8f0;
    --accent: #4488cc;
    --accent-glow: rgba(68, 136, 204, 0.15);
    --green: #3a9a5a;
    --green-dim: rgba(58, 154, 90, 0.15);
    --yellow: #c8a832;
    --yellow-dim: rgba(200, 168, 50, 0.15);
    --blue: #4488cc;
    --blue-dim: rgba(68, 136, 204, 0.15);
    --orange: #d48a3c;
    --orange-dim: rgba(212, 138, 60, 0.15);
    --purple: #8866bb;
    --pink: #cc6688;
    --mono: 'IBM Plex Mono', 'Consolas', monospace;
    --sans: 'IBM Plex Sans', 'Segoe UI', sans-serif;
    --radius: 4px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.6;
    height: 100vh;
    overflow: hidden;
}
a { color: var(--blue); text-decoration: none; }
a:hover { text-decoration: underline; }

.topbar {
    background: var(--bg-card);
    border-bottom: 1px solid var(--border);
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    z-index: 100;
}
.topbar-title {
    font-family: var(--mono);
    font-weight: 600;
    font-size: 15px;
    color: var(--accent);
    letter-spacing: 2px;
    text-transform: uppercase;
    text-decoration: none;
}
.topbar-sep { color: var(--border); }
.topbar-crumb {
    font-family: var(--mono);
    font-size: 13px;
    color: var(--text-dim);
}

.graph-layout {
    display: flex;
    height: calc(100vh - 49px);
}

.graph-area {
    flex: 1;
    position: relative;
    overflow: hidden;
}
.graph-area svg {
    width: 100%;
    height: 100%;
}

.graph-controls {
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 10;
    display: flex;
    gap: 8px;
    align-items: center;
}
.search-box {
    font-family: var(--mono);
    font-size: 12px;
    padding: 6px 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    width: 240px;
    outline: none;
}
.search-box:focus { border-color: var(--accent); }
.search-box::placeholder { color: var(--text-dim); }

.graph-legend {
    position: absolute;
    bottom: 16px;
    left: 16px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--text-dim);
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
}
.legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.graph-stats {
    position: absolute;
    bottom: 16px;
    right: 16px;
    font-family: var(--mono);
    font-size: 11px;
    color: var(--text-dim);
    text-align: right;
}

.detail-panel {
    width: 380px;
    background: var(--bg-card);
    border-left: 1px solid var(--border);
    overflow-y: auto;
    padding: 0;
    transition: width 0.2s;
}
.detail-panel.collapsed {
    width: 0;
    padding: 0;
    border: none;
    overflow: hidden;
}
.detail-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    background: var(--bg-card);
    z-index: 5;
}
.detail-name {
    font-family: var(--mono);
    font-size: 18px;
    font-weight: 600;
    color: var(--text-bright);
    margin-bottom: 4px;
}
.detail-title {
    font-size: 13px;
    color: var(--text-dim);
    margin-bottom: 8px;
}
.detail-faction {
    display: inline-block;
    font-family: var(--mono);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 2px 8px;
    border-radius: 2px;
}
.detail-section {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
}
.detail-section-title {
    font-family: var(--mono);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-dim);
    margin-bottom: 8px;
}
.detail-bio {
    font-size: 13px;
    line-height: 1.6;
    color: var(--text);
}
.detail-list {
    list-style: none;
    padding: 0;
}
.detail-list li {
    font-family: var(--mono);
    font-size: 12px;
    padding: 4px 0;
    color: var(--text);
    border-bottom: 1px solid rgba(42,42,50,0.5);
}
.detail-list li:last-child { border: none; }
.detail-empty {
    color: var(--text-dim);
    font-size: 12px;
    font-style: italic;
}
.connection-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.connection-name { color: var(--text-bright); cursor: pointer; }
.connection-name:hover { color: var(--accent); }
.connection-docs {
    font-size: 10px;
    color: var(--text-dim);
}

.detail-empty-state {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-dim);
    font-family: var(--mono);
    font-size: 12px;
}

.node-tooltip {
    position: absolute;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 6px 10px;
    font-family: var(--mono);
    font-size: 11px;
    color: var(--text-bright);
    pointer-events: none;
    z-index: 50;
    white-space: nowrap;
    display: none;
}
</style>
</head>
<body>

<div class="topbar">
    <a href="index.php" class="topbar-title"><?= e($app_title) ?></a>
    <span class="topbar-sep">&#9656;</span>
    <span class="topbar-crumb">Entity Graph</span>
    <span class="topbar-sep" style="margin-left:auto"></span>
    <a href="index.php?page=lore" style="font-family:var(--mono); font-size:12px; color:var(--text-dim)">Lore Bible</a>
    <a href="index.php" style="font-family:var(--mono); font-size:12px; color:var(--text-dim)">Dashboard</a>
</div>

<div class="graph-layout">
    <div class="graph-area" id="graph-area">
        <div class="graph-controls">
            <input type="text" class="search-box" id="search" placeholder="Search entities...">
        </div>
        <div class="graph-legend" id="legend"></div>
        <div class="graph-stats" id="stats"></div>
        <div class="node-tooltip" id="tooltip"></div>
        <svg id="graph-svg"></svg>
    </div>
    <div class="detail-panel" id="detail-panel">
        <div class="detail-empty-state" id="detail-empty">
            Click an entity node to view their profile, connections, and document appearances.
        </div>
        <div id="detail-content" style="display:none"></div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
<script>
(function() {
    var data = <?= $graph_json ?>;
    var nodes = data.nodes;
    var edges = data.edges;

    if (nodes.length === 0) {
        document.getElementById('detail-empty').innerHTML =
            'No character or entity entries found in the Lore Bible.<br><br>' +
            '<a href="index.php?page=lore_create" style="color:var(--accent)">Create your first entry</a>';
        return;
    }

    // Dynamic faction colors based on data
    var factionSet = new Set(nodes.map(function(n) { return n.faction; }));
    var factionList = Array.from(factionSet).sort();
    var colorScale = d3.scaleOrdinal(d3.schemeTableau10);
    var factionColors = {};
    factionList.forEach(function(f, i) {
        factionColors[f] = colorScale(i);
    });

    var adjacency = {};
    nodes.forEach(function(n) { adjacency[n.id] = []; });
    edges.forEach(function(e) {
        adjacency[e.source] = adjacency[e.source] || [];
        adjacency[e.target] = adjacency[e.target] || [];
        adjacency[e.source].push(e);
        adjacency[e.target].push(e);
    });

    var container = document.getElementById('graph-area');
    var svg = d3.select('#graph-svg');
    var width = container.clientWidth;
    var height = container.clientHeight;

    svg.attr('viewBox', [0, 0, width, height]);

    var g = svg.append('g');

    var zoom = d3.zoom()
        .scaleExtent([0.2, 5])
        .on('zoom', function(event) {
            g.attr('transform', event.transform);
        });
    svg.call(zoom);

    var simulation = d3.forceSimulation(nodes)
        .force('link', d3.forceLink(edges).id(function(d) { return d.id; })
            .distance(100)
            .strength(0.3))
        .force('charge', d3.forceManyBody()
            .strength(function(d) { return -200 - d.appearances * 20; }))
        .force('center', d3.forceCenter(width / 2, height / 2))
        .force('collision', d3.forceCollide().radius(function(d) {
            return nodeRadius(d) + 4;
        }))
        .alphaDecay(0.02);

    function nodeRadius(d) {
        return Math.max(6, Math.min(24, 6 + d.appearances * 2));
    }

    var linkGroup = g.append('g').attr('class', 'links');
    var link = linkGroup.selectAll('line')
        .data(edges)
        .enter().append('line')
        .attr('stroke', '#2a2a32')
        .attr('stroke-opacity', 0.3)
        .attr('stroke-width', function(d) {
            return Math.max(1, Math.min(4, d.docs.length));
        });

    var nodeGroup = g.append('g').attr('class', 'nodes');
    var node = nodeGroup.selectAll('g')
        .data(nodes)
        .enter().append('g')
        .attr('cursor', 'pointer')
        .call(d3.drag()
            .on('start', dragstarted)
            .on('drag', dragged)
            .on('end', dragended));

    node.append('circle')
        .attr('r', nodeRadius)
        .attr('fill', function(d) { return factionColors[d.faction] || '#3a3a44'; })
        .attr('stroke', 'rgba(0,0,0,0.3)')
        .attr('stroke-width', 1.5);

    node.append('text')
        .text(function(d) { return d.name; })
        .attr('dx', function(d) { return nodeRadius(d) + 4; })
        .attr('dy', '0.35em')
        .attr('font-family', "'IBM Plex Mono', monospace")
        .attr('font-size', '10px')
        .attr('fill', '#6a6a78')
        .attr('pointer-events', 'none');

    var selectedNode = null;
    var tooltip = document.getElementById('tooltip');

    node.on('click', function(event, d) {
        event.stopPropagation();
        selectNode(d);
    });

    node.on('mouseenter', function(event, d) {
        if (selectedNode && selectedNode.id === d.id) return;
        tooltip.textContent = d.name + (d.title ? ' — ' + d.title.substring(0, 50) : '');
        tooltip.style.display = 'block';
        tooltip.style.left = (event.clientX + 12) + 'px';
        tooltip.style.top = (event.clientY - 8) + 'px';
    });

    node.on('mousemove', function(event) {
        tooltip.style.left = (event.clientX + 12) + 'px';
        tooltip.style.top = (event.clientY - 8) + 'px';
    });

    node.on('mouseleave', function() {
        tooltip.style.display = 'none';
    });

    svg.on('click', function() {
        deselectAll();
    });

    function deselectAll() {
        selectedNode = null;
        node.select('circle').attr('opacity', 1);
        node.select('text').attr('opacity', 1).attr('fill', '#6a6a78');
        link.attr('stroke-opacity', 0.3);
        document.getElementById('detail-empty').style.display = '';
        document.getElementById('detail-content').style.display = 'none';
    }

    function selectNode(d) {
        selectedNode = d;
        tooltip.style.display = 'none';
        var fColor = factionColors[d.faction] || '#3a3a44';

        var connIds = new Set([d.id]);
        (adjacency[d.id] || []).forEach(function(e) {
            var s = typeof e.source === 'object' ? e.source.id : e.source;
            var t = typeof e.target === 'object' ? e.target.id : e.target;
            connIds.add(s);
            connIds.add(t);
        });

        node.select('circle').attr('opacity', function(n) { return connIds.has(n.id) ? 1 : 0.15; });
        node.select('text')
            .attr('opacity', function(n) { return connIds.has(n.id) ? 1 : 0.1; })
            .attr('fill', function(n) { return n.id === d.id ? fColor : (connIds.has(n.id) ? '#c8c8d0' : '#6a6a78'); });
        link.attr('stroke-opacity', function(e) {
            var s = typeof e.source === 'object' ? e.source.id : e.source;
            var t = typeof e.target === 'object' ? e.target.id : e.target;
            return (s === d.id || t === d.id) ? 0.7 : 0.03;
        });

        var empty = document.getElementById('detail-empty');
        var content = document.getElementById('detail-content');
        empty.style.display = 'none';
        content.style.display = '';

        var connections = [];
        (adjacency[d.id] || []).forEach(function(e) {
            var s = typeof e.source === 'object' ? e.source.id : e.source;
            var t = typeof e.target === 'object' ? e.target.id : e.target;
            var otherId = s === d.id ? t : s;
            var otherNode = nodes.find(function(n) { return n.id === otherId; });
            if (otherNode) {
                var existing = connections.find(function(c) { return c.id === otherId; });
                if (existing) {
                    e.docs.forEach(function(doc) {
                        if (existing.docs.indexOf(doc) === -1) existing.docs.push(doc);
                    });
                } else {
                    connections.push({
                        id: otherId,
                        name: otherNode.name,
                        slug: otherNode.slug,
                        faction: otherNode.faction,
                        docs: e.docs.slice(),
                    });
                }
            }
        });
        connections.sort(function(a, b) { return b.docs.length - a.docs.length; });

        var connHtml = '';
        if (connections.length > 0) {
            connections.forEach(function(c) {
                var cColor = factionColors[c.faction] || '#3a3a44';
                connHtml += '<li class="connection-item">' +
                    '<span class="connection-name" data-id="' + c.id + '" style="border-left:3px solid ' + cColor + '; padding-left:6px">' +
                    escHtml(c.name) + '</span>' +
                    '<span class="connection-docs">' + c.docs.join(', ') + '</span></li>';
            });
        }

        var docsHtml = '';
        if (d.docs.length > 0) {
            d.docs.forEach(function(doc) {
                docsHtml += '<li>' + escHtml(doc) + '</li>';
            });
        }

        content.innerHTML =
            '<div class="detail-header">' +
                '<div class="detail-name">' + escHtml(d.name) + '</div>' +
                '<div class="detail-title">' + escHtml(d.title) + '</div>' +
                '<span class="detail-faction" style="background:' + hexToRgba(fColor, 0.15) + '; color:' + fColor + '">' + escHtml(d.faction) + '</span>' +
                (d.slug ? '<a href="index.php?page=lore_view&entry=' + encodeURIComponent(d.slug) + '" style="display:inline-block; margin-left:8px; font-family:var(--mono); font-size:10px; color:var(--blue)">View in Lore Bible</a>' : '') +
            '</div>' +
            (d.bio ? '<div class="detail-section"><div class="detail-section-title">Bio</div><div class="detail-bio">' + escHtml(d.bio) + '</div></div>' : '') +
            '<div class="detail-section"><div class="detail-section-title">Connections (' + connections.length + ')</div>' +
                (connHtml ? '<ul class="detail-list">' + connHtml + '</ul>' : '<div class="detail-empty">No direct connections found.</div>') +
            '</div>' +
            '<div class="detail-section"><div class="detail-section-title">Appears In (' + d.docs.length + ')</div>' +
                (docsHtml ? '<ul class="detail-list">' + docsHtml + '</ul>' : '<div class="detail-empty">No document appearances.</div>') +
            '</div>';

        content.querySelectorAll('.connection-name').forEach(function(el) {
            el.addEventListener('click', function() {
                var id = parseInt(this.getAttribute('data-id'));
                var target = nodes.find(function(n) { return n.id === id; });
                if (target) selectNode(target);
            });
        });
    }

    function escHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function hexToRgba(hex, alpha) {
        var r = parseInt(hex.slice(1,3), 16);
        var g = parseInt(hex.slice(3,5), 16);
        var b = parseInt(hex.slice(5,7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    var searchBox = document.getElementById('search');
    searchBox.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        if (!q) {
            deselectAll();
            return;
        }
        var matches = nodes.filter(function(n) {
            return n.name.toLowerCase().indexOf(q) !== -1 ||
                   n.title.toLowerCase().indexOf(q) !== -1 ||
                   n.faction.toLowerCase().indexOf(q) !== -1;
        });
        if (matches.length === 1) {
            selectNode(matches[0]);
        } else if (matches.length > 0) {
            var matchIds = new Set(matches.map(function(m) { return m.id; }));
            node.select('circle').attr('opacity', function(n) { return matchIds.has(n.id) ? 1 : 0.15; });
            node.select('text')
                .attr('opacity', function(n) { return matchIds.has(n.id) ? 1 : 0.1; })
                .attr('fill', function(n) { return matchIds.has(n.id) ? '#e8e8f0' : '#6a6a78'; });
            link.attr('stroke-opacity', 0.05);
        }
    });

    var legendEl = document.getElementById('legend');
    var factions = {};
    nodes.forEach(function(n) { factions[n.faction] = (factions[n.faction] || 0) + 1; });
    Object.keys(factions).sort().forEach(function(f) {
        var item = document.createElement('span');
        item.className = 'legend-item';
        item.innerHTML = '<span class="legend-dot" style="background:' + (factionColors[f] || '#3a3a44') + '"></span>' +
            f + ' (' + factions[f] + ')';
        item.addEventListener('click', function() {
            searchBox.value = f;
            searchBox.dispatchEvent(new Event('input'));
        });
        legendEl.appendChild(item);
    });

    document.getElementById('stats').innerHTML =
        nodes.length + ' entities &middot; ' +
        edges.length + ' connections';

    simulation.on('tick', function() {
        link
            .attr('x1', function(d) { return d.source.x; })
            .attr('y1', function(d) { return d.source.y; })
            .attr('x2', function(d) { return d.target.x; })
            .attr('y2', function(d) { return d.target.y; });
        node.attr('transform', function(d) { return 'translate(' + d.x + ',' + d.y + ')'; });
    });

    function dragstarted(event) {
        if (!event.active) simulation.alphaTarget(0.3).restart();
        event.subject.fx = event.subject.x;
        event.subject.fy = event.subject.y;
    }
    function dragged(event) {
        event.subject.fx = event.x;
        event.subject.fy = event.y;
    }
    function dragended(event) {
        if (!event.active) simulation.alphaTarget(0);
        event.subject.fx = null;
        event.subject.fy = null;
    }

    window.addEventListener('resize', function() {
        width = container.clientWidth;
        height = container.clientHeight;
        svg.attr('viewBox', [0, 0, width, height]);
        simulation.force('center', d3.forceCenter(width / 2, height / 2));
        simulation.alpha(0.3).restart();
    });

})();
</script>
</body>
</html>
