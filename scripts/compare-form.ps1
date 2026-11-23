# Normalize the supplied screenshot to the web paper's width without stretching text.
# The screenshot is cropped shorter than A4; remaining bottom area is white.
Add-Type -AssemblyName System.Drawing
$projectPath = Split-Path -Parent $PSScriptRoot
$outputPath = Join-Path $projectPath 'docs/form-preview'
$sourceImage = [System.Drawing.Image]::FromFile((Join-Path $projectPath 'docs/reference/original-form.png'))
$webImage = [System.Drawing.Image]::FromFile((Join-Path $outputPath 'blank-desktop.png'))
$normalizedImage = [System.Drawing.Bitmap]::new($webImage.Width, $webImage.Height)
$graphics = [System.Drawing.Graphics]::FromImage($normalizedImage)
$graphics.Clear([System.Drawing.Color]::White)
$scaleFactor = $webImage.Width / 653.0
$graphics.DrawImage($sourceImage, [System.Drawing.RectangleF]::new(0, 0, $webImage.Width, (889 * $scaleFactor)), [System.Drawing.RectangleF]::new(7, 2, 653, 889), [System.Drawing.GraphicsUnit]::Pixel)
$normalizedImage.Save((Join-Path $outputPath 'original-normalized.png'))
$graphics.Dispose()
$comparison = [System.Drawing.Bitmap]::new(($webImage.Width * 2), $webImage.Height)
$graphics = [System.Drawing.Graphics]::FromImage($comparison)
$graphics.DrawImageUnscaled($normalizedImage, 0, 0)
$graphics.DrawImageUnscaled($webImage, $webImage.Width, 0)
$comparison.Save((Join-Path $outputPath 'reference-vs-web.png'))
$graphics.Dispose()
$comparison.Dispose()
# Overlay uses opacity only for QA; the live document is never scaled or blended.
$overlay = [System.Drawing.Bitmap]::new($webImage.Width, $webImage.Height)
$graphics = [System.Drawing.Graphics]::FromImage($overlay)
$graphics.DrawImageUnscaled($normalizedImage, 0, 0)
$opacity = [System.Drawing.Imaging.ColorMatrix]::new()
$opacity.Matrix33 = 0.5
$attributes = [System.Drawing.Imaging.ImageAttributes]::new()
$attributes.SetColorMatrix($opacity)
$graphics.DrawImage($webImage, [System.Drawing.Rectangle]::new(0, 0, $webImage.Width, $webImage.Height), 0, 0, $webImage.Width, $webImage.Height, [System.Drawing.GraphicsUnit]::Pixel, $attributes)
$overlay.Save((Join-Path $outputPath 'reference-overlay.png'))
$graphics.Dispose()
$attributes.Dispose()
$overlay.Dispose()
$beforePath = Join-Path $outputPath 'refinement-before/requester-desktop.png'
if (Test-Path -LiteralPath $beforePath) {
    $beforeImage = [System.Drawing.Image]::FromFile($beforePath)
    $afterImage = [System.Drawing.Image]::FromFile((Join-Path $outputPath 'requester-desktop.png'))
    $beforeAfter = [System.Drawing.Bitmap]::new(($afterImage.Width * 2), ([Math]::Max($beforeImage.Height, $afterImage.Height)))
    $graphics = [System.Drawing.Graphics]::FromImage($beforeAfter)
    $graphics.Clear([System.Drawing.Color]::White)
    $graphics.DrawImageUnscaled($beforeImage, 0, 0)
    $graphics.DrawImageUnscaled($afterImage, $afterImage.Width, 0)
    $beforeAfter.Save((Join-Path $outputPath 'refinement-before-after.png'))
    $graphics.Dispose()
    $beforeAfter.Dispose()
    $beforeImage.Dispose()
    $afterImage.Dispose()
}
$normalizedImage.Dispose()
$webImage.Dispose()
$sourceImage.Dispose()
