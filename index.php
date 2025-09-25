<?php
// Helper: escape textarea safely
function h($s) { return htmlspecialchars($s, ENT_QUOTES); }

$netlist = $_POST['netlist'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Unique filename
    $filename = 'temp_' . uniqid() . '.cir';
    file_put_contents($filename, $netlist);

    // Run ngspice (adjust path if needed)
    $ngspice = '/data/data/com.termux/files/usr/bin/ngspice';
    $cmd = escapeshellarg($ngspice) . ' -b ' . escapeshellarg($filename) . ' 2>&1';
    $output = shell_exec($cmd);

    // Build HTML
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>SPICE Runner</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 10px; background: #f9f9f9; color: #111; }
        h1 { font-size: 1.4em; text-align: center; margin-bottom: 8px; }
        .container { max-width: 1000px; margin: 0 auto; }
        form { margin-bottom: 12px; }
        textarea { width: 100%; height: 260px; font-family: monospace; font-size: 13px; padding: 8px; box-sizing: border-box; }
        input[type=submit] { padding: 10px 18px; font-size: 15px; margin-top: 8px; }
        pre { background: #111; color: #eaeaea; padding: 10px; overflow-x: auto; font-size: 13px; border-radius: 4px; }
        .controls { margin-top: 10px; display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
        select { padding:6px; }
        .hint { font-size:12px; color:#444; }
        .legend { margin-top:8px; font-size:13px; }
        .legend-item { display:flex; align-items:center; gap:8px; margin:4px 0; }
        .color-box { width:14px; height:14px; display:inline-block; border:1px solid #666; }
        .plot-block { margin-top:12px; padding:8px; background:#fff; border:1px solid #ddd; border-radius:6px; }
        canvas { width:100%; height:240px; display:block; border:1px solid #ccc; background:#fff; }
        label.inline { display:inline-flex; align-items:center; gap:6px; }
        @media (max-width:600px) {
            textarea { height:180px; font-size:12px; }
            .controls { flex-direction:column; align-items:flex-start; }
            input[type=submit] { width:100%; }
            canvas { height:200px; }
        }
    </style></head><body>";
    echo "<div class='container'><h1>SPICE Runner</h1>";

    // Netlist form (persisted)
    echo "<form method='post'>
            <textarea name='netlist' placeholder='Paste your SPICE netlist here...'>" . h($netlist) . "</textarea><br>
            <input type='submit' value='Run ngspice'>
          </form>";

    // ngspice output
    echo "<h3>ngspice output</h3>";
    echo "<pre>" . h($output) . "</pre>";

    // Check CSV
    $csvfile = dirname($filename) . '/sim.csv';
    if (file_exists($csvfile)) {
        $lines = file($csvfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = preg_split('/\s+/', $line);
            if (count($parts) > 1) {
                $row = array_map('floatval', $parts);
                $data[] = $row;
            }
        }

        if (count($data) > 0) {
            $data_json = json_encode($data);
            $numCols = count($data[0]);

            echo "<h3>Simulation Plots</h3>";
            echo "<div class='controls'>
                    <label>X: <select id='xcol'></select></label>
                    <label>Y (multi): <select id='ycol' multiple size='6'></select></label>
                    <label class='inline'><input type='checkbox' id='compare'> Compare (single combined graph)</label>
                    <span class='hint'>(Hold Ctrl/Cmd or tap multiple to select several Y columns)</span>
                  </div>";
            echo "<div id='plots-container'></div>";

            // Client-side plotting: one canvas per selected Y or compare single canvas
            echo "<script>
            const data = $data_json;
            const numCols = $numCols;
            const xsel = document.getElementById('xcol');
            const ysel = document.getElementById('ycol');
            const compareCheckbox = document.getElementById('compare');
            const plotsContainer = document.getElementById('plots-container');

            // populate selects
            for (let i=0; i<numCols; i++) {
                const label = 'Column ' + (i+1);
                const o1 = document.createElement('option'); o1.value = i; o1.text = label; xsel.add(o1);
                const o2 = document.createElement('option'); o2.value = i; o2.text = label; ysel.add(o2);
            }
            xsel.selectedIndex = 0;
            if (ysel.options.length > 1) ysel.options[1].selected = true;

            const colors = ['#1f77b4','#d62728','#2ca02c','#ff7f0e','#9467bd','#8c564b','#e377c2','#17becf','#7f7f7f','#bcbd22'];

            function getSelectedYCols() {
                return Array.from(ysel.selectedOptions).map(o => parseInt(o.value));
            }

            function computeMinMax(arr) {
                const finiteArr = arr.filter(Number.isFinite);
                if (finiteArr.length === 0) return {min: 0, max: 0};
                return {min: Math.min(...finiteArr), max: Math.max(...finiteArr)};
            }

            function createPlotBlock(idx, colIndex, color, xarr, yarr, xColIndex) {
                const block = document.createElement('div');
                block.className = 'plot-block';
                // title + legend with min/max
                const mm = computeMinMax(yarr);
                const title = document.createElement('div');
                title.style.display = 'flex';
                title.style.justifyContent = 'space-between';
                title.style.alignItems = 'center';
                title.style.marginBottom = '6px';
                const left = document.createElement('div');
                left.innerHTML = '<strong>Plot: Column ' + (colIndex+1) + ' vs Column ' + (xColIndex+1) + '</strong>';
                const right = document.createElement('div');
                right.innerHTML = '<span class=\"color-box\" style=\"background:' + color + '; margin-right:8px\"></span> min: ' + mm.min + ' , max: ' + mm.max;
                title.appendChild(left);
                title.appendChild(right);

                const canvas = document.createElement('canvas');
                canvas.id = 'plot_canvas_' + idx;
                canvas.width = plotsContainer.clientWidth;
                canvas.height = 240;

                block.appendChild(title);
                block.appendChild(canvas);

                return {block, canvas};
            }

            function drawSinglePlot(canvas, xarr, yarr, color, xLabel, yLabel) {
                const ctx = canvas.getContext('2d');
                // pixel ratio fix
                const rect = canvas.getBoundingClientRect();
                const dpr = window.devicePixelRatio || 1;
                canvas.width = rect.width * dpr;
                canvas.height = rect.height * dpr;
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                ctx.clearRect(0,0,canvas.width/dpr,canvas.height/dpr);

                const margin = 60; // Increased margin for longer labels
                const W = canvas.width/dpr;
                const H = canvas.height/dpr;
                const w = W - margin*2;
                const h = H - margin*2;

                const xmm = computeMinMax(xarr);
                const xmin = xmm.min, xmax = xmm.max;
                const ymm = computeMinMax(yarr);
                const ymin = ymm.min, ymax = ymm.max;
                const xscale = v => margin + ((v - xmin) / (xmax - xmin || 1)) * w;
                const yscale = v => margin + h - ((v - ymin) / (ymax - ymin || 1)) * h;

                // grid
                ctx.strokeStyle = '#eee';
                ctx.lineWidth = 1;
                ctx.beginPath();
                for (let i=0;i<=5;i++){
                    const gx = margin + i*(w/5);
                    ctx.moveTo(gx, margin); ctx.lineTo(gx, margin + h);
                }
                for (let j=0;j<=5;j++){
                    const gy = margin + j*(h/5);
                    ctx.moveTo(margin, gy); ctx.lineTo(margin + w, gy);
                }
                ctx.stroke();

                // axes
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 1.2;
                ctx.beginPath();
                ctx.moveTo(margin, margin);
                ctx.lineTo(margin, margin + h);
                ctx.moveTo(margin, margin + h);
                ctx.lineTo(margin + w, margin + h);
                ctx.stroke();

                // ticks & labels for x (with rotation to prevent overlap)
                ctx.fillStyle = '#000';
                ctx.font = '12px sans-serif';
                for (let i=0;i<=4;i++){
                    const t = xmin + i*(xmax-xmin)/4;
                    const x = xscale(t);
                    const label = Number.isFinite(t) ? t.toExponential(3) : t;
                    ctx.save();
                    ctx.translate(x, margin + h + 8);
                    ctx.rotate(-Math.PI / 4);
                    ctx.textAlign = 'right';
                    ctx.fillText(label, 0, 0);
                    ctx.restore();
                }
                // y ticks (left, adjusted position)
                ctx.textAlign = 'right';
                for (let j=0;j<=4;j++){
                    const v = ymin + j*(ymax-ymin)/4;
                    const y = yscale(v);
                    const label = Number.isFinite(v) ? v.toExponential(3) : v;
                    ctx.fillText(label, margin - 8, y + 4);
                }

                // plot line
                ctx.strokeStyle = color;
                ctx.lineWidth = 1.6;
                ctx.beginPath();
                for (let i=0;i<xarr.length;i++){
                    const px = xscale(xarr[i]);
                    const py = yscale(yarr[i]);
                    if (i===0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
                }
                ctx.stroke();

                // Removed axis labels
            }

            function drawCombinedPlot(canvas, xarr, ycols, colorList, xColIndex, yColLabels) {
                const ctx = canvas.getContext('2d');
                const rect = canvas.getBoundingClientRect();
                const dpr = window.devicePixelRatio || 1;
                canvas.width = rect.width * dpr;
                canvas.height = rect.height * dpr;
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                ctx.clearRect(0,0,canvas.width/dpr,canvas.height/dpr);

                const margin = 60; // Increased margin
                const W = canvas.width/dpr;
                const H = canvas.height/dpr;
                const w = W - margin*2;
                const h = H - margin*2;

                const xmm = computeMinMax(xarr);
                const xmin = xmm.min, xmax = xmm.max;

                // compute combined y range
                let allY = [];
                ycols.forEach(c => allY = allY.concat(data.map(r => r[c]).filter(Number.isFinite)));
                const ymm = computeMinMax(allY);
                const ymin = ymm.min, ymax = ymm.max;

                const xscale = v => margin + ((v - xmin) / (xmax - xmin || 1)) * w;
                const yscale = v => margin + h - ((v - ymin) / (ymax - ymin || 1)) * h;

                // grid
                ctx.strokeStyle = '#eee';
                ctx.lineWidth = 1;
                ctx.beginPath();
                for (let i=0;i<=5;i++){
                    const gx = margin + i*(w/5);
                    ctx.moveTo(gx, margin); ctx.lineTo(gx, margin + h);
                }
                for (let j=0;j<=5;j++){
                    const gy = margin + j*(h/5);
                    ctx.moveTo(margin, gy); ctx.lineTo(margin + w, gy);
                }
                ctx.stroke();

                // axes
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 1.2;
                ctx.beginPath();
                ctx.moveTo(margin, margin);
                ctx.lineTo(margin, margin + h);
                ctx.moveTo(margin, margin + h);
                ctx.lineTo(margin + w, margin + h);
                ctx.stroke();

                // ticks & labels for x (with rotation)
                ctx.fillStyle = '#000';
                ctx.font = '12px sans-serif';
                for (let i=0;i<=4;i++){
                    const t = xmin + i*(xmax-xmin)/4;
                    const x = xscale(t);
                    const label = Number.isFinite(t) ? t.toExponential(3) : t;
                    ctx.save();
                    ctx.translate(x, margin + h + 8);
                    ctx.rotate(-Math.PI / 4);
                    ctx.textAlign = 'right';
                    ctx.fillText(label, 0, 0);
                    ctx.restore();
                }

                // y ticks (left, added for combined plot)
                ctx.textAlign = 'right';
                for (let j=0;j<=4;j++){
                    const v = ymin + j*(ymax-ymin)/4;
                    const y = yscale(v);
                    const label = Number.isFinite(v) ? v.toExponential(3) : v;
                    ctx.fillText(label, margin - 8, y + 4);
                }

                // plot each y
                ycols.forEach((c, idx) => {
                    const arr = data.map(r => r[c]);
                    const color = colorList[idx % colorList.length];
                    ctx.strokeStyle = color;
                    ctx.lineWidth = 1.6;
                    ctx.beginPath();
                    for (let i=0;i<xarr.length;i++){
                        const px = xscale(xarr[i]);
                        const py = yscale(arr[i]);
                        if (i===0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
                    }
                    ctx.stroke();
                });

                // legend
                const legendX = margin + 8;
                let legendY = margin + 8;
                ctx.font = '12px sans-serif';
                ctx.textAlign = 'left';
                yColLabels.forEach((lbl, idx) => {
                    ctx.fillStyle = colorList[idx % colorList.length];
                    ctx.fillRect(legendX, legendY - 10, 12, 8);
                    ctx.fillStyle = '#000';
                    ctx.fillText(lbl, legendX + 18, legendY);
                    legendY += 18;
                });

                // Removed axis labels
            }

            function renderPlots() {
                const scrollY = window.scrollY;
                // clear previous
                plotsContainer.innerHTML = '';
                const xcol = parseInt(xsel.value);
                const ycols = getSelectedYCols();
                if (!ycols.length) {
                    plotsContainer.innerHTML = '<p class=\"hint\">No Y column selected</p>';
                    window.scrollTo(0, scrollY);
                    return;
                }
                const xarr = data.map(r => r[xcol]);

                if (compareCheckbox.checked) {
                    // single combined canvas
                    const block = document.createElement('div');
                    block.className = 'plot-block';
                    const title = document.createElement('div');
                    title.style.marginBottom = '6px';
                    title.innerHTML = '<strong>Combined Plot: multiple Y columns vs Column ' + (xcol+1) + '</strong>';
                    const canvas = document.createElement('canvas');
                    canvas.id = 'combined_plot_canvas';
                    canvas.style.width = '100%';
                    canvas.height = 360;
                    block.appendChild(title);
                    block.appendChild(canvas);
                    plotsContainer.appendChild(block);

                    const yLabels = ycols.map(c => 'Col ' + (c+1));
                    drawCombinedPlot(canvas, xarr, ycols, colors, xcol, yLabels);

                    // show per-line min/max below combined plot
                    const info = document.createElement('div');
                    info.className = 'legend';
                    ycols.forEach((c, idx) => {
                        const mm = computeMinMax(data.map(r => r[c]));
                        const item = document.createElement('div');
                        item.className = 'legend-item';
                        item.innerHTML = '<span class=\"color-box\" style=\"background:' + colors[idx % colors.length] + '\"></span> Col ' + (c+1) + ' — min: ' + mm.min + ' , max: ' + mm.max;
                        info.appendChild(item);
                    });
                    plotsContainer.appendChild(info);

                } else {
                    // separate canvas per selected y
                    ycols.forEach((c, idx) => {
                        const yarr = data.map(r => r[c]);
                        const color = colors[idx % colors.length];
                        const {block, canvas} = createPlotBlock(idx, c, color, xarr, yarr, xcol);
                        plotsContainer.appendChild(block);
                        drawSinglePlot(canvas, xarr, yarr, color, 'Column ' + (xcol+1), 'Column ' + (c+1));
                    });
                }
                window.scrollTo(0, scrollY);
            }

            // initial render
            renderPlots();

            xsel.addEventListener('change', renderPlots);
            ysel.addEventListener('change', renderPlots);
            compareCheckbox.addEventListener('change', renderPlots);
            window.addEventListener('resize', renderPlots);

            </script>";
        } else {
            echo "<p>No numeric rows found in sim.csv.</p>";
        }
        unlink($csvfile);
    } else {
        echo "<p><em>No sim.csv found. Make sure your netlist writes CSV using wrdata sim.csv or similar in a .control block.</em></p>";
    }

    echo "</div></body></html>";

    // cleanup netlist
    unlink($filename);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SPICE Runner</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; background: #f9f9f9; color: #111; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { font-size: 1.4em; text-align: center; }
        textarea { width: 100%; height: 260px; font-family: monospace; font-size: 13px; padding: 8px; box-sizing: border-box; }
        input[type=submit] { padding: 10px 18px; font-size: 15px; margin-top: 8px; }
        @media (max-width:600px) { textarea { height:180px; font-size:12px; } input[type=submit]{width:100%;} }
    </style>
</head>
<body>
<div class="container">
    <h1>SPICE Runner</h1>
    <form method="post">
        <textarea name="netlist" placeholder="Paste your SPICE netlist here..."><?= h($netlist) ?></textarea><br>
        <input type="submit" value="Run ngspice">
    </form>
    <p class="hint">Tip: include a .control block with <code>wrdata sim.csv ...</code> to generate CSV for plotting.</p>
</div>
</body>
</html>
