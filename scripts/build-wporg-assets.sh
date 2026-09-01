#!/usr/bin/env bash
#
# Regenerates the WordPress.org directory assets in .wordpress-org/.
#
# Those files are not part of the plugin ZIP; they are committed so that the
# banner and icon can be rebuilt from source instead of from a design tool.
# The 10up/action-wordpress-plugin-deploy convention maps .wordpress-org/ onto
# the assets/ directory of the plugin's Subversion repository.
#
# The artwork follows the ElevenLabs brand guidelines at
# https://elevenlabs.io/brand:
#
#   * The wordmark and the "11" symbol are their official artwork, taken from
#     the SVG downloads on that page and scaled uniformly, never redrawn. Their
#     don'ts call out recreating the symbol from "I" characters or "11", so the
#     real geometry is used.
#   * The palette is the monochrome one they specify for ElevenAPI: black,
#     white, and neutral tones. No invented accent colour.
#   * The symbol SVG already carries their clear-space rule, a holding shape
#     three times the height of the icon, so it is scaled to fill the icon tile
#     rather than padded by hand.
#
# The ground is a grainy monochrome sweep rather than flat black, and the
# WordPress mark sits on it at low opacity, so that the artwork reads as a
# WordPress plugin rather than as an official ElevenLabs one. The plugin is not
# affiliated with ElevenLabs.
#
# Requires ImageMagick (magick).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/.wordpress-org"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

if ! command -v magick >/dev/null 2>&1; then
    echo "Error: ImageMagick (magick) is required to build the WordPress.org assets." >&2
    exit 1
fi

# Fixed seed, so rebuilding the assets reproduces the same artwork instead of
# rolling a new cloud pattern every time.
SEED=11

# How far the ground is allowed to travel, as a share of full white. Keeping the
# top well under a quarter is what makes it read as near-black with light in it
# rather than as grey.
GROUND_FLOOR="2%"
GROUND_CEILING="22%"

# Opacity of the WordPress mark over the ground.
WATERMARK_ALPHA="0.08"

# The WordPress logo, from the official single-colour SVG.
WP_LOGO_PATH="M8.708,61.26c0,20.802,12.089,38.779,29.619,47.298L13.258,39.872C10.342,46.408,8.708,53.641,8.708,61.26z M96.74,58.608c0-6.495-2.333-10.993-4.334-14.494c-2.664-4.329-5.161-7.995-5.161-12.324c0-4.831,3.664-9.328,8.825-9.328c0.233,0,0.454,0.029,0.681,0.042c-9.35-8.566-21.807-13.796-35.489-13.796c-18.36,0-34.513,9.42-43.91,23.688c1.233,0.037,2.395,0.063,3.382,0.063c5.497,0,14.006-0.667,14.006-0.667c2.833-0.167,3.167,3.994,0.337,4.329c0,0-2.847,0.335-6.015,0.501L48.2,93.547l11.501-34.493l-8.188-22.434c-2.83-0.166-5.511-0.501-5.511-0.501c-2.832-0.166-2.5-4.496,0.332-4.329c0,0,8.679,0.667,13.843,0.667c5.496,0,14.006-0.667,14.006-0.667c2.835-0.167,3.168,3.994,0.337,4.329c0,0-2.853,0.335-6.015,0.501l18.992,56.494l5.242-17.517C95.011,68.328,96.74,63.107,96.74,58.608z M62.184,65.857l-15.768,45.819c4.708,1.384,9.687,2.141,14.846,2.141c6.12,0,11.989-1.058,17.452-2.979c-0.141-0.225-0.269-0.464-0.374-0.724L62.184,65.857z M107.376,36.046c0.226,1.674,0.354,3.471,0.354,5.404c0,5.333-0.996,11.328-3.996,18.824l-16.053,46.413c15.624-9.111,26.133-26.038,26.133-45.426C113.815,52.124,111.481,43.532,107.376,36.046z M61.262,0C27.483,0,0,27.481,0,61.26c0,33.783,27.483,61.263,61.262,61.263c33.778,0,61.265-27.48,61.265-61.263C122.526,27.481,95.04,0,61.262,0z M61.262,119.715c-32.23,0-58.453-26.223-58.453-58.455c0-32.23,26.222-58.451,58.453-58.451c32.229,0,58.45,26.221,58.45,58.451C119.712,93.492,93.491,119.715,61.262,119.715z"

# The ElevenLabs wordmark, from elevenlabs-logo-white.svg. 694x90 user units.
ELEVENLABS_WORDMARK='<path d="M248.261 22.1901H230.466L251.968 88.5124H271.123L292.625 22.1901H274.83L261.365 72.1488L248.261 22.1901Z"/> <path d="M0 0H18.413V88.5124H0V0Z"/> <path d="M36.5788 0H54.9917V88.5124H36.5788V0Z"/> <path d="M73.1551 0H127.652V14.7521H91.568V35.8264H125.181V50.5785H91.568V73.7603H127.652V88.5124H73.1551V0Z"/> <path d="M138.896 0H156.32V88.5124H138.896V0Z"/> <path d="M166.824 55.2893C166.824 31.1157 178.811 20.7025 197.471 20.7025C216.131 20.7025 226.759 30.9917 226.759 55.5372V59.5041H184.001C184.619 73.8843 188.944 78.719 197.224 78.719C203.773 78.719 207.851 74.876 208.593 68.1818H226.017C224.905 82.8099 212.795 90 197.224 90C177.452 90 166.824 79.4628 166.824 55.2893ZM209.582 47.9752C208.717 35.8264 204.515 31.8595 197.224 31.8595C189.933 31.8595 185.36 35.9504 184.125 47.9752H209.582Z"/> <path d="M295.962 55.2893C295.962 31.1157 307.949 20.7025 326.609 20.7025C345.269 20.7025 355.897 30.9917 355.897 55.5372V59.5041H313.139C313.757 73.8843 318.082 78.719 326.362 78.719C332.911 78.719 336.989 74.876 337.731 68.1818H355.155C354.043 82.8099 341.932 90 326.362 90C306.589 90 295.962 79.4628 295.962 55.2893ZM338.719 47.9752C337.854 35.8264 333.653 31.8595 326.362 31.8595C319.071 31.8595 314.498 35.9504 313.263 47.9752H338.719Z"/> <path d="M438.443 0H456.856V73.7603H491.457V88.5124H438.443V0Z"/> <path fill-rule="evenodd" clip-rule="evenodd" d="M495.783 55.2893C495.783 30 507.399 20.7025 522.352 20.7025C529.766 20.7025 536.563 24.9174 539.282 29.3802V22.1901H557.077V88.5124H539.776V80.7025C537.181 85.9091 529.89 90 521.857 90C506.04 90 495.783 79.8347 495.783 55.2893ZM526.924 33.719C535.574 33.719 540.27 40.2893 540.27 55.2893C540.27 70.2893 535.574 76.9835 526.924 76.9835C518.274 76.9835 513.331 70.2893 513.331 55.2893C513.331 40.2893 518.274 33.719 526.924 33.719Z"/> <path fill-rule="evenodd" clip-rule="evenodd" d="M587.847 80.7025V88.5124H570.547V0H587.971V29.3802C590.937 24.7934 597.857 20.7025 605.272 20.7025C619.854 20.7025 631.47 30 631.47 55.2893C631.47 80.5785 620.101 90 604.901 90C596.869 90 590.319 85.9091 587.847 80.7025ZM600.329 33.843C608.979 33.843 613.922 40.2893 613.922 55.2893C613.922 70.2893 608.979 76.9835 600.329 76.9835C591.678 76.9835 586.982 70.2893 586.982 55.2893C586.982 40.2893 591.678 33.843 600.329 33.843Z"/> <path d="M638.638 68.8017H656.062C656.309 75.7438 660.016 79.0909 666.566 79.0909C673.115 79.0909 676.823 76.1157 676.823 70.9091C676.823 66.1983 673.981 64.4628 667.802 62.9752L662.488 61.6116C647.412 57.7686 639.873 53.6777 639.873 41.157C639.873 28.6364 651.49 20.7025 666.319 20.7025C681.148 20.7025 692.394 26.5289 692.888 40.2893H675.463C675.093 34.2149 671.385 31.6116 666.072 31.6116C660.758 31.6116 657.05 34.2149 657.05 39.1736C657.05 43.7603 660.016 45.4959 665.207 46.7355L670.644 48.0992C684.979 51.6942 694 55.2893 694 68.6777C694 82.0661 682.137 90 666.072 90C648.647 90 639.008 83.4297 638.638 68.8017Z"/> <path d="M384.072 49.4628C384.072 39.0496 389.015 33.3471 396.677 33.3471C402.979 33.3471 406.563 37.314 406.563 45.8678V88.5124H423.987V43.1405C423.987 27.7686 415.337 20.7025 402.732 20.7025C394.205 20.7025 387.162 25.0413 384.072 30.7438V22.1901H366.401V88.5124H384.072V49.4628Z"/>'

# The ElevenLabs "11" symbol, from elevenlabs-symbol.svg. 876x876 user units,
# where the two bars are 292 tall, so the box is exactly the clear space their
# guidelines ask for.
ELEVENLABS_SYMBOL='<path d="M468 292H528V584H468V292Z"/><path d="M348 292H408V584H348V292Z"/>'

# Paints the grainy monochrome ground: random noise blurred into soft blobs,
# stretched to the target size, then flattened into a narrow dark range and
# overlaid with fine film grain.
#
# The grain is rendered at the final size rather than downsampled into it,
# because scaling averages it away; that is why each output size is painted
# from scratch instead of resized from the largest one.
#
# $1 width, $2 height, $3 blob source width, $4 blob source height, $5 output
ground() {
    local width="$1" height="$2" blob_w="$3" blob_h="$4" out="$5"

    magick -seed "${SEED}" -size "${blob_w}x${blob_h}" xc: +noise Random \
        -virtual-pixel tile -blur 0x4 -auto-level -colorspace Gray \
        -resize "${width}x${height}!" +level "${GROUND_FLOOR},${GROUND_CEILING}" \
        \( -seed "${SEED}" -size "${width}x${height}" xc:gray50 +noise Gaussian -colorspace Gray \) \
        -compose Overlay -composite \
        -depth 8 -strip "${out}"
}

# Renders an SVG layer on transparency, optionally fading it, and lays it over
# the ground.
#
# $1 svg, $2 ground/target, $3 width, $4 height, $5 alpha (1 for opaque)
overlay() {
    local svg="$1" target="$2" width="$3" height="$4" alpha="$5"
    local layer="${WORK_DIR}/layer-$$.png"

    magick -background none "${svg}" -resize "${width}x${height}!" \
        -alpha set -channel A -evaluate multiply "${alpha}" +channel "${layer}"
    magick "${target}" "${layer}" -compose Over -composite -depth 8 -strip "${target}"
    rm -f "${layer}"
}

# banner --------------------------------------------------------------------
# The WordPress mark bleeds off the left edge; the ElevenLabs wordmark sits in
# the open space to its right, with more than its own height clear on every
# side as their guidelines require.
banner_watermark_svg() {
    local width="$1" height="$2" scale="$3" x="$4" y="$5"
    printf '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 %s %s">' \
        "${width}" "${height}" "${width}" "${height}"
    printf '<g transform="translate(%s,%s) scale(%s)" fill="#FFFFFF">' "${x}" "${y}" "${scale}"
    printf '<path d="%s"/>' "${WP_LOGO_PATH}"
    printf '</g></svg>'
}

banner_wordmark_svg() {
    local width="$1" height="$2" scale="$3" x="$4" y="$5"
    printf '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 %s %s">' \
        "${width}" "${height}" "${width}" "${height}"
    printf '<g transform="translate(%s,%s) scale(%s)" fill="#FFFFFF">%s</g></svg>' \
        "${x}" "${y}" "${scale}" "${ELEVENLABS_WORDMARK}"
}

build_banner() {
    local width="$1" height="$2" blob_w="$3" blob_h="$4" out="$5"
    # The layout is authored at 1544x500 and drawn straight into whatever size
    # is asked for, so both banners are the same composition at their own
    # resolution rather than one resampled from the other.
    ground "${width}" "${height}" "${blob_w}" "${blob_h}" "${out}"
    banner_watermark_svg 1544 500 4.2 -80 -20 > "${WORK_DIR}/banner-wp.svg"
    banner_wordmark_svg 1544 500 1 642 205 > "${WORK_DIR}/banner-el.svg"
    overlay "${WORK_DIR}/banner-wp.svg" "${out}" "${width}" "${height}" "${WATERMARK_ALPHA}"
    overlay "${WORK_DIR}/banner-el.svg" "${out}" "${width}" "${height}" 1
}

build_banner 1544 500 70 24 "${OUT_DIR}/banner-1544x500.png"
build_banner 772 250 35 12 "${OUT_DIR}/banner-772x250.png"

# icon ----------------------------------------------------------------------
# The symbol is scaled to the full tile, which reproduces their clear-space
# rule exactly: a third of the tile is the height of the bars.
{
    printf '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">'
    printf '<g transform="translate(28,28) scale(1.63)" fill="#FFFFFF">'
    printf '<path d="%s"/>' "${WP_LOGO_PATH}"
    printf '</g></svg>'
} > "${WORK_DIR}/icon-wp.svg"

{
    printf '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">'
    printf '<g transform="scale(0.29224)" fill="#FFFFFF">%s</g></svg>' "${ELEVENLABS_SYMBOL}"
} > "${WORK_DIR}/icon-el.svg"

build_icon() {
    local size="$1" blob="$2" out="$3"
    ground "${size}" "${size}" "${blob}" "${blob}" "${out}"
    overlay "${WORK_DIR}/icon-wp.svg" "${out}" "${size}" "${size}" "${WATERMARK_ALPHA}"
    overlay "${WORK_DIR}/icon-el.svg" "${out}" "${size}" "${size}" 1
}

build_icon 256 12 "${OUT_DIR}/icon-256x256.png"
build_icon 128 8 "${OUT_DIR}/icon-128x128.png"

# The SVG icon cannot carry the grain, so it keeps the same composition on a
# flat near-black drawn from the middle of the ground's range.
{
    printf '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">'
    printf '<rect width="256" height="256" fill="#141418"/>'
    printf '<g transform="translate(28,28) scale(1.63)" fill="#23232A">'
    printf '<path d="%s"/>' "${WP_LOGO_PATH}"
    printf '</g>'
    printf '<g transform="scale(0.29224)" fill="#FFFFFF">%s</g>' "${ELEVENLABS_SYMBOL}"
    printf '</svg>'
} > "${OUT_DIR}/icon.svg"

echo "Wrote WordPress.org assets to ${OUT_DIR}"
