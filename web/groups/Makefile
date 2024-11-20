.PHONY: all

all: hlt_summary.json hlt_no_gpu.json

hlt_summary.json: hlt.json
	@cat hlt.json | sed \
  -e 's#: "other|other"#: "non-event processing"#' \
  -e 's#: "event setup"#: "non-event processing"#' \
  -e 's#: "idle"#: "non-event processing"#' \
  -e 's#: "AlCa\>#: "others#' \
  -e 's#: "B tagging\>#: "others#' \
  -e 's#: "CTPPS\>#: "others#' \
  -e 's#: "DQM\>#: "others#' \
  -e 's#: "Framework\>#: "others#' \
  -e 's#: "HLT\>#: "others#' \
  -e 's#: "I/O\>#: "others#' \
  -e 's#: "L1T\>#: "others#' \
  -e 's#: "TOTEM\>#: "others#' \
  -e 's#: "ZDC\>#: "others#' \
  -e '/PixelVertex/s#: "Vertices\>#: "Pixel track and vertex#' \
  -e 's#: "Pixels\>#: "Pixel track and vertex#' \
  -e 's#: "Tracking\>#: "Full track and vertex#' \
  -e 's#: "Vertices\>#: "Full track and vertex#' \
  -e 's#|\(Alpaka\|Conversion\|GPU\)\>##' \
  > hlt_summary.json

hlt_no_gpu.json: hlt.json
	@cat hlt.json | sed -e 's#|\(Alpaka\|GPU\)\>##' > hlt_no_gpu.json
