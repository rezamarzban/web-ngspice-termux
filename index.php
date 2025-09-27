<?php
// Helper: escape textarea safely
function h($s) { return htmlspecialchars($s, ENT_QUOTES); }

$netlist = $_POST['netlist'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Parse netlist for possible CSV files from wrdata commands
    $possible_csvs = [];
    $lines = explode("\n", $netlist);
    $in_control = false;
    foreach ($lines as $line) {
        $trimmed = trim(strtolower($line));
        if (strpos($trimmed, '.control') === 0) $in_control = true;
        if (strpos($trimmed, '.endc') === 0) $in_control = false;
        if ($in_control && strpos($trimmed, 'wrdata') === 0) {
            preg_match('/wrdata\s+([^\s]+)/i', $trimmed, $matches);
            if (isset($matches[1])) {
                $file = $matches[1];
                if (!in_array($file, $possible_csvs)) $possible_csvs[] = $file;
            }
        }
    }

    // Unique filename for netlist
    $filename = 'temp_' . uniqid() . '.cir';
    file_put_contents($filename, $netlist);

    // Run ngspice (adjust path if needed)
    $ngspice = '/data/data/com.termux/files/usr/bin/ngspice';
    $cmd = escapeshellarg($ngspice) . ' -b ' . escapeshellarg($filename) . ' 2>&1';
    $output = shell_exec($cmd);

    // Find existing CSVs with data
    $data_array = [];
    $valid_csvs = [];
    foreach ($possible_csvs as $csv) {
        if (file_exists($csv)) {
            $lines = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
                $data_array[$csv] = $data;
                $valid_csvs[] = $csv;
            }
        }
    }

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

    if (empty($valid_csvs)) {
        echo "<p><em>No valid CSV files with data found. Make sure your netlist writes CSV using wrdata filename.csv in a .control block.</em></p>";
    } else {
        $data_json = json_encode($data_array);
        $default_csv = $valid_csvs[0];

        echo "<h3>Simulation Plots</h3>";
        echo "<div class='controls'>";
        if (count($valid_csvs) > 1) {
            echo "<label>CSV: <select id='csv_select'>";
            foreach ($valid_csvs as $csv) {
                echo "<option value='" . h($csv) . "' " . ($csv === $default_csv ? 'selected' : '') . ">" . h($csv) . "</option>";
            }
            echo "</select></label>";
        }
        echo "<label>X: <select id='xcol'></select></label>
              <label>Y (multi): <select id='ycol' multiple size='6'></select></label>
              <label class='inline'><input type='checkbox' id='compare'> Compare (single combined graph)</label>
              <span class='hint'>(Hold Ctrl/Cmd or tap multiple to select several Y columns)</span>
              </div>";
        echo "<div id='plots-container'></div>";

        // Client-side plotting
        echo "<script>
        const csvData = $data_json;
        let currentCsv = '" . $default_csv . "';
        let data = csvData[currentCsv];
        let numCols = data[0] ? data[0].length : 0;
        const xsel = document.getElementById('xcol');
        const ysel = document.getElementById('ycol');
        const compareCheckbox = document.getElementById('compare');
        const plotsContainer = document.getElementById('plots-container');

        const colors = ['#1f77b4','#d62728','#2ca02c','#ff7f0e','#9467bd','#8c564b','#e377c2','#17becf','#7f7f7f','#bcbd22'];

        function populateCols() {
            xsel.innerHTML = '';
            ysel.innerHTML = '';
            for (let i=0; i<numCols; i++) {
                const label = 'Column ' + (i+1);
                const o1 = document.createElement('option'); o1.value = i; o1.text = label; xsel.add(o1);
                const o2 = document.createElement('option'); o2.value = i; o2.text = label; ysel.add(o2);
            }
            xsel.selectedIndex = 0;
            if (ysel.options.length > 1) ysel.options[1].selected = true;
        }

        populateCols();

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

        function drawSinglePlot(canvas, xarr, yarr, color) {
            const ctx = canvas.getContext('2d');
            const rect = canvas.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            ctx.clearRect(0,0,canvas.width/dpr,canvas.height/dpr);

            const margin = 60;
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

            ctx.strokeStyle = '#000';
            ctx.lineWidth = 1.2;
            ctx.beginPath();
            ctx.moveTo(margin, margin);
            ctx.lineTo(margin, margin + h);
            ctx.moveTo(margin, margin + h);
            ctx.lineTo(margin + w, margin + h);
            ctx.stroke();

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
            ctx.textAlign = 'right';
            for (let j=0;j<=4;j++){
                const v = ymin + j*(ymax-ymin)/4;
                const y = yscale(v);
                const label = Number.isFinite(v) ? v.toExponential(3) : v;
                ctx.fillText(label, margin - 8, y + 4);
            }

            ctx.strokeStyle = color;
            ctx.lineWidth = 1.6;
            ctx.beginPath();
            for (let i=0;i<xarr.length;i++){
                const px = xscale(xarr[i]);
                const py = yscale(yarr[i]);
                if (i===0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
            }
            ctx.stroke();
        }

        function drawCombinedPlot(canvas, xarr, ycols, colorList, yColLabels) {
            const ctx = canvas.getContext('2d');
            const rect = canvas.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            ctx.clearRect(0,0,canvas.width/dpr,canvas.height/dpr);

            const margin = 60;
            const W = canvas.width/dpr;
            const H = canvas.height/dpr;
            const w = W - margin*2;
            const h = H - margin*2;

            const xmm = computeMinMax(xarr);
            const xmin = xmm.min, xmax = xmm.max;

            let allY = [];
            ycols.forEach(c => allY = allY.concat(data.map(r => r[c]).filter(Number.isFinite)));
            const ymm = computeMinMax(allY);
            const ymin = ymm.min, ymax = ymm.max;

            const xscale = v => margin + ((v - xmin) / (xmax - xmin || 1)) * w;
            const yscale = v => margin + h - ((v - ymin) / (ymax - ymin || 1)) * h;

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

            ctx.strokeStyle = '#000';
            ctx.lineWidth = 1.2;
            ctx.beginPath();
            ctx.moveTo(margin, margin);
            ctx.lineTo(margin, margin + h);
            ctx.moveTo(margin, margin + h);
            ctx.lineTo(margin + w, margin + h);
            ctx.stroke();

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

            ctx.textAlign = 'right';
            for (let j=0;j<=4;j++){
                const v = ymin + j*(ymax-ymin)/4;
                const y = yscale(v);
                const label = Number.isFinite(v) ? v.toExponential(3) : v;
                ctx.fillText(label, margin - 8, y + 4);
            }

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
        }

        function renderPlots() {
            const scrollY = window.scrollY;
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
                drawCombinedPlot(canvas, xarr, ycols, colors, yLabels);

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
                ycols.forEach((c, idx) => {
                    const yarr = data.map(r => r[c]);
                    const color = colors[idx % colors.length];
                    const {block, canvas} = createPlotBlock(idx, c, color, xarr, yarr, xcol);
                    plotsContainer.appendChild(block);
                    drawSinglePlot(canvas, xarr, yarr, color);
                });
            }
            window.scrollTo(0, scrollY);
        }

        renderPlots();

        xsel.addEventListener('change', renderPlots);
        ysel.addEventListener('change', renderPlots);
        compareCheckbox.addEventListener('change', renderPlots);
        window.addEventListener('resize', renderPlots);
        ";

        if (count($valid_csvs) > 1) {
            echo "
            const csvsel = document.getElementById('csv_select');
            csvsel.addEventListener('change', () => {
                currentCsv = csvsel.value;
                data = csvData[currentCsv];
                numCols = data[0] ? data[0].length : 0;
                populateCols();
                renderPlots();
            });
            ";
        }

        echo "</script>";
    }

    echo "</div></body></html>";

    // Cleanup
    unlink($filename);
    foreach ($possible_csvs as $csv) {
        if (file_exists($csv)) unlink($csv);
    }
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
    <p class="hint">Tip: first line of netlist is not included in simulation by ngspice, so do not write netlist code at first line, use it for comment. include a .control block with <code>wrdata filename.csv ...</code> to generate CSV for plotting, This app recognize the name of CSV file(s) in the provided netlist and plot data.</p>
</div>
</body>
</html>
