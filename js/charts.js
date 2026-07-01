/**
 * js/charts.js — Chart.js 4.x helper wrappers for StockBoard Dealer.
 * Destroys previous chart instance before re-rendering (no memory leaks).
 */
const _charts = {};

function _base() {
  return {
    responsive: true, maintainAspectRatio: true,
    plugins: {
      legend: { labels: { color: '#64748b', font: { size: 11 } } },
      tooltip: {
        backgroundColor: '#ffffff', borderColor: '#e2e8f0', borderWidth: 1,
        titleColor: '#0f172a', bodyColor: '#64748b',
        callbacks: { label: ctx => ' ₱' + Number(ctx.parsed.y ?? ctx.parsed).toLocaleString('en-PH', {minimumFractionDigits:2}) }
      }
    },
    scales: {
      x: { ticks: { color: '#64748b', font:{size:10} }, grid: { color: '#e2e8f0' } },
      y: { ticks: { color: '#64748b', font:{size:10},
             callback: v => '₱' + Number(v).toLocaleString('en-PH',{minimumFractionDigits:0})
           }, grid: { color: '#e2e8f0' }, beginAtZero: true }
    }
  };
}

function killChart(id) {
  const ctx = document.getElementById(id);
  if (!ctx) return;

  // 1. Destroy existing instance via native registry
  const existing = Chart.getChart(id);
  if (existing) {
    existing.destroy();
  }

  // 2. Clear internal tracker
  if (_charts[id]) delete _charts[id];

  // 3. Nuclear option: replace the canvas element with a clone
  // This purges all listeners and internal buffers.
  const parent = ctx.parentNode;
  const newCanvas = ctx.cloneNode(true);
  parent.replaceChild(newCanvas, ctx);
}

function renderBar(id, labels, data, opt = {}) {
  killChart(id);
  const ctx = document.getElementById(id); if (!ctx) return;
  const colors = opt.colors || ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0891b2','#be185d'];
  _charts[id] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{ label: opt.label || 'Amount', data,
        backgroundColor: data.map((_,i) => colors[i % colors.length]),
        borderRadius: 4, borderSkipped: false }]
    },
    options: _base()
  });
}

function renderLine(id, labels, data, opt = {}) {
  killChart(id);
  const ctx = document.getElementById(id); if (!ctx) return;
  const color = opt.color || '#2563eb';
  const b = _base();
  _charts[id] = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{ label: opt.label || 'Revenue (₱)', data,
        borderColor: color, backgroundColor: color + '1a',
        borderWidth: 2, pointBackgroundColor: color, pointRadius: 3,
        fill: true, tension: 0.35 }]
    },
    options: b
  });
}

function renderPie(id, labels, data, colors) {
  killChart(id);
  const ctx = document.getElementById(id); if (!ctx) return;
  const pal = colors || ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed'];
  _charts[id] = new Chart(ctx, {
    type: 'pie',
    data: {
      labels,
      datasets: [{ data, backgroundColor: data.map((_,i) => pal[i%pal.length]),
        borderColor:'#ffffff', borderWidth:2, hoverOffset:6 }]
    },
    options: {
      responsive:true, maintainAspectRatio:true,
      plugins: {
        legend: { position:'bottom', labels:{ color:'#64748b', font:{size:11}, padding:10 } },
        tooltip: {
          backgroundColor:'#ffffff', borderColor:'#e2e8f0', borderWidth:1,
          titleColor:'#0f172a', bodyColor:'#64748b',
          callbacks: { label: ctx => ` ₱${Number(ctx.parsed).toLocaleString('en-PH',{minimumFractionDigits:2})}` }
        }
      }
    }
  });
}

function renderStackedBar(id, labels, datasetsData, opt = {}) {
  killChart(id);
  const ctx = document.getElementById(id); if (!ctx) return;
  const pal = opt.colors || ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0891b2','#be185d','#ea580c','#4f46e5'];
  
  const datasets = [];
  let colorIdx = 0;
  for (const [catName, dataArr] of Object.entries(datasetsData)) {
      datasets.push({
          label: catName,
          data: dataArr,
          backgroundColor: pal[colorIdx % pal.length]
      });
      colorIdx++;
  }

  const b = _base();
  b.scales.x.stacked = true;
  b.scales.y.stacked = true;
  
  _charts[id] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: datasets
    },
    options: b
  });
}
