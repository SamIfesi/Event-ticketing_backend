#!/usr/bin/env node

/**
 * browsershot.js
 *
 * This is the Node.js bridge script that Spatie Browsershot calls
 * internally when PHP asks it to generate a PDF.
 *
 * You do NOT call this directly — Browsershot handles it.
 * Just make sure this file exists at the project root and is executable.
 *
 * What it does:
 *   1. PHP (Browsershot) spawns this Node script as a child process
 *   2. This script launches Chromium via Puppeteer
 *   3. Chromium renders the HTML → PDF
 *   4. PDF bytes are written to the output path
 *   5. PHP reads the file
 *
 * Chromium flags explained:
 *   --no-sandbox            Required in Docker (root user has no sandbox)
 *   --disable-setuid-sandbox Same as above, belt and braces
 *   --disable-dev-shm-usage  /dev/shm is too small in Docker by default,
 *                            use /tmp instead to avoid crashes
 *   --disable-gpu            No GPU in a headless container
 *   --single-process         Avoids zombie processes in Docker
 *   --no-zygote              Needed alongside --single-process
 */

const puppeteer = require('puppeteer');

(async () => {
  // Read the request JSON piped from PHP via stdin
  let inputData = '';
  process.stdin.on('data', (chunk) => (inputData += chunk));

  process.stdin.on('end', async () => {
    let request;

    try {
      request = JSON.parse(inputData);
    } catch (e) {
      console.error('browsershot.js: Failed to parse input JSON:', e.message);
      process.exit(1);
    }

    const isLinux = process.platform === 'linux';

    const browser = await puppeteer.launch({
      executablePath:
        process.env.PUPPETEER_EXECUTABLE_PATH ||
        (isLinux ? '/usr/bin/chromium' : undefined),
      headless: 'new',
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--disable-gpu',
        '--no-first-run',
        '--no-zygote',
        '--single-process',
        '--disable-extensions',
        '--font-render-hinting=none', // Cleaner text rendering in PDF
      ],
    });

    try {
      const page = await browser.newPage();

      // Set viewport to match our ticket width
      await page.setViewport({
        width: 360,
        height: 600,
        deviceScaleFactor: 2, // Retina-quality output
      });

      // Load the HTML — either from a URL or raw HTML string
      if (request.url) {
        await page.goto(request.url, {
          waitUntil: 'networkidle0',
          timeout: 30000,
        });
      } else if (request.html) {
        await page.setContent(request.html, {
          waitUntil: 'networkidle0',
          timeout: 30000,
        });
      }

      // Wait for any web fonts or images to finish loading
      await page.evaluateHandle('document.fonts.ready');

      // Generate the PDF
      const pdfOptions = {
        path: request.outputFile || undefined,
        format: request.format || undefined,
        width: request.width || undefined,
        height: request.height || undefined,
        printBackground: true,
        margin: {
          top: request.marginTop ?? '0',
          right: request.marginRight ?? '0',
          bottom: request.marginBottom ?? '0',
          left: request.marginLeft ?? '0',
        },
      };

      // If the request is for a screenshot instead of a PDF, adjust options accordingly
      if (request.type === 'screenshot') {
        const screenshotOptions = {
          path: request.outputFile || undefined,
          type: request.screenshotType || 'png',
          fullPage: request.fullPage ?? false,
          clip: request.clip || undefined,
        };
        const img = await page.screenshot(screenshotOptions);
        if(!request.outputFile) {
          process.stdout.write(img);
        }
      } else {
        const pdf = await page.pdf(pdfOptions);

        // If no outputFile was set, write to stdout so PHP can read it
        if (!request.outputFile) {
          process.stdout.write(pdf);
        }
      }

      await browser.close();
      process.exit(0);
    } catch (err) {
      console.error('browsershot.js: PDF generation failed:', err.message);
      await browser.close();
      process.exit(1);
    }
  });
})();
