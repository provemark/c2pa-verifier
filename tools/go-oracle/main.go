// A second opinion: the Go verifier's verdict on one file, as JSON on stdout.
// Tooling for the C2PA_Verifier project (step 61); not part of any product.
package main

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"os"
	"strings"

	c2pa "github.com/richardwooding/c2pa"
)

type status struct {
	Code     string `json:"code"`
	Severity string `json:"severity"`
}

type out struct {
	File     string   `json:"file"`
	Valid    bool     `json:"valid"`
	Binding  string   `json:"binding"`
	Active   string   `json:"active_manifest"`
	Statuses []status `json:"statuses"`
	Error    string   `json:"error,omitempty"`
}

func main() {
	if len(os.Args) < 3 {
		fmt.Fprintln(os.Stderr, "usage: oracle <file> <format> [anchors.pem]")
		os.Exit(2)
	}
	path, format := os.Args[1], strings.ToLower(os.Args[2])
	var opts []c2pa.ValidateOption
	if len(os.Args) > 3 {
		pem, err := os.ReadFile(os.Args[3])
		if err != nil {
			panic(err)
		}
		pool := x509.NewCertPool()
		pool.AppendCertsFromPEM(pem)
		opts = append(opts, c2pa.WithSigningTrust(pool), c2pa.WithTimestampTrust(pool))
	}
	f, err := os.Open(path)
	if err != nil {
		panic(err)
	}
	defer f.Close()

	var kind c2pa.Container
	switch format {
	case "jpeg", "jpg":
		kind = c2pa.JPEG
	case "png":
		kind = c2pa.PNG
	case "webp":
		kind = c2pa.RIFF
	default:
		fmt.Fprintln(os.Stderr, "unknown format "+format)
		os.Exit(2)
	}

	r := c2pa.Validate(context.Background(), kind, f, opts...)
	result := out{File: path, Valid: r.Valid, Binding: fmt.Sprint(r.Binding), Active: r.ActiveManifestLabel}
	for _, s := range r.Statuses {
		result.Statuses = append(result.Statuses, status{Code: fmt.Sprint(s.Code), Severity: fmt.Sprint(s.Severity)})
	}
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	_ = enc.Encode(result)
}
