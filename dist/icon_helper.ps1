function New-IcoFromPng {
    param(
        [Parameter(Mandatory)] [string]$PngPath,
        [Parameter(Mandatory)] [string]$IcoPath,
        [int[]]$Sizes = @(256, 48, 32, 16)
    )
    Add-Type -AssemblyName System.Drawing

    $srcImage = [System.Drawing.Image]::FromFile($PngPath)
    $images = @()
    foreach ($size in $Sizes) {
        $bmp = New-Object System.Drawing.Bitmap $size, $size
        $g = [System.Drawing.Graphics]::FromImage($bmp)
        $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $g.Clear([System.Drawing.Color]::Transparent)
        $g.DrawImage($srcImage, 0, 0, $size, $size)
        $g.Dispose()

        $ms = New-Object System.IO.MemoryStream
        $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
        $bmp.Dispose()
        $images += [PSCustomObject]@{ Size = $size; Bytes = $ms.ToArray() }
    }
    $srcImage.Dispose()

    $out = New-Object System.IO.MemoryStream
    $bw = New-Object System.IO.BinaryWriter($out)
    $bw.Write([UInt16]0)
    $bw.Write([UInt16]1)
    $bw.Write([UInt16]$images.Count)

    $offset = 6 + (16 * $images.Count)
    foreach ($img in $images) {
        $dim = if ($img.Size -ge 256) { 0 } else { $img.Size }
        $bw.Write([byte]$dim)
        $bw.Write([byte]$dim)
        $bw.Write([byte]0)
        $bw.Write([byte]0)
        $bw.Write([UInt16]1)
        $bw.Write([UInt16]32)
        $bw.Write([UInt32]$img.Bytes.Length)
        $bw.Write([UInt32]$offset)
        $offset += $img.Bytes.Length
    }
    foreach ($img in $images) { $bw.Write($img.Bytes) }
    $bw.Flush()
    [IO.File]::WriteAllBytes($IcoPath, $out.ToArray())
    $bw.Dispose()
    $out.Dispose()
}
