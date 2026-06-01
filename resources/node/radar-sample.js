import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, RadarController, RadialLinearScale } from 'chart.js';
import { Canvas } from 'skia-canvas';
import fsp from 'node:fs/promises';

Chart.register([
  CategoryScale,
  RadarController,
  RadialLinearScale,
  LineController,
  LineElement,
  Filler,
  PointElement
]);

const canvas = new Canvas(400, 300);
const chart = new Chart(
  canvas, // TypeScript needs "as any" here
  {
    type: 'radar',
    data: {
      labels: ["Eating", "Drinking", "Sleeping", "Designing", "Coding", "Cycling", "Running"],
      datasets: [
        {
          label: "My First Dataset",
          data: [65, 59, 90, 81, 56, 55, 40],
          fill: true,
          backgroundColor: "rgba(255, 99, 132, 0.2)",
          borderColor: "rgb(255, 99, 132)",
          pointBackgroundColor: "rgb(255, 99, 132)",
          pointBorderColor: "#fff",
          pointHoverBackgroundColor: "#fff",
          pointHoverBorderColor: "rgb(255, 99, 132)"
        },
        {
          label: "My Second Dataset",
          data: [28, 48, 40, 19, 96, 27, 100],
          fill: true,
          backgroundColor: "rgba(54, 162, 235, 0.2)",
          borderColor: "rgb(54, 162, 235)",
          pointBackgroundColor: "rgb(54, 162, 235)",
          pointBorderColor: "#fff",
          pointHoverBackgroundColor: "#fff",
          pointHoverBorderColor: "rgb(54, 162, 235)"
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        title: {
          display: true,
          text: 'Chart.js Radar Chart'
        }
      }
    },
  },
);
const pngBuffer = await canvas.toBuffer('png', { matte: 'white' });
const outputPath = './storage/app/private/output.png';
await fsp.writeFile(outputPath, pngBuffer);
chart.destroy();
