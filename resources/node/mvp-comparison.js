import { CategoryScale, Chart, Filler, Legend, LinearScale, LineController, LineElement, PointElement, RadarController, RadialLinearScale } from 'chart.js';
import { Canvas } from 'skia-canvas';
import fsp from 'node:fs/promises';
import path from 'node:path';

Chart.register([
  CategoryScale,
  RadarController,
  RadialLinearScale,
  LineController,
  LineElement,
  Filler,
  PointElement,
  Legend,
]);

// Read full stdin
const chunks = [];
for await (const chunk of process.stdin) {
  chunks.push(chunk);
}
const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));

const { outputPath, players, weights } = input;

const labels = [
  'Partisipasi Kill', // 'Kill Participation',
  'Hero Damage', // 'Hero Dmg. Share',
  'Tower Damage', // 'Tower Dmg. Share',
  'Dampak Healing', // 'Healing Impact',
  'Normalisasi KDA', // 'KDA Normalized',
  'Skor Efisiensi', // 'Efficiency Score',
  'Keterampilan Hero', // 'Skill Level',
];

const colors = [
  { border: 'rgb(255, 99, 132)', background: 'rgba(255, 99, 132, 0.2)', point: 'rgb(255, 99, 132)' },
  { border: 'rgb(54, 162, 235)', background: 'rgba(54, 162, 235, 0.2)', point: 'rgb(54, 162, 235)' },
  { border: 'rgb(75, 192, 192)', background: 'rgba(75, 192, 192, 0.2)', point: 'rgb(75, 192, 192)' },
  { border: 'rgb(255, 159, 64)', background: 'rgba(255, 159, 64, 0.2)', point: 'rgb(255, 159, 64)' },
  { border: 'rgb(153, 102, 255)', background: 'rgba(153, 102, 255, 0.2)', point: 'rgb(153, 102, 255)' },
];

const formulas = [
  '(K+A) / Team Kill', // Kill Participation
  'Hero Dmg. / Team Hero Dmg.', // Hero Damage Share
  'Tower Dmg. / Team Tower Dmg.', // Tower Damage Share
  'Team Share + Benchmark', // Healing Impact
  '(Kill + Assist) / Death', // KDA Normalized
  '(Dmg. + Heal) / Net Worth', // Efficiency Score
  'Avg. GPM + XPM', // Economy Percentile
];

const showFormula = false;

const datasets = players.map((player, i) => {
  const c = colors[i] ?? colors[0];
  return {
    label: player.name,
    // scores are 0–1; multiply by 100 for 0–100 axis
    data: player.scores.map(s => Math.round(s * 100)),
    fill: true,
    backgroundColor: c.background,
    borderColor: c.border,
    pointBackgroundColor: c.point,
    pointBorderColor: '#fff',
    pointHoverBackgroundColor: '#fff',
    pointHoverBorderColor: c.border,
  };
});

// Ensure output directory exists
await fsp.mkdir(path.dirname(outputPath), { recursive: true });

const canvas = new Canvas(500, showFormula ? 500 : 400);
const chart = new Chart(
  canvas,
  {
    type: 'radar',
    data: {
      labels: labels.map((item, index) => {
        const weight = weights[index] ?? 0;
        const weightPercent = Math.round(weight * 100);

        return [item, `(Bobot ${weightPercent}%)`, !showFormula ? '' : formulas[index]].filter(Boolean);
      }),
      datasets,
    },
    options: {
      responsive: false,
      layout: !showFormula ? {} : {
        padding: {
          bottom: 116,
        }
      },
      scales: {
        r: {
          min: 0,
          max: 100,
          ticks: { stepSize: 20 },
        },
      },
      plugins: {
        legend: {
          display: true,
          position: 'top',
        },
      },
    },
    plugins: !showFormula ? [] : [
      {
        id: 'customText',
        beforeDraw(chart) {
          const ctx = chart.ctx;
          ctx.save();
          ctx.font = '12px Arial';
          ctx.fillStyle = 'black';

          // Position the formulas below the chart
          const startX = 10;
          let startY = chart.height - 100;

          formulas.forEach((formula, index) => {
            ctx.fillText(`${labels[index]}: ${formula}`, startX, startY);
            startY += 15; // Line spacing
          });

          ctx.restore();
        },
      },
    ],
  },
);

const pngBuffer = await canvas.toBuffer('png', { matte: 'white' });
await fsp.writeFile(outputPath, pngBuffer);
chart.destroy();
