#!/bin/sh
# One-shot: wait for SeaweedFS S3, create the private bucket, exit.
set -eu
export AWS_EC2_METADATA_DISABLED=true
EP="${AWS_ENDPOINT:-http://seaweedfs:8333}"
for i in $(seq 1 60); do
  if aws --endpoint-url "$EP" s3 ls >/dev/null 2>&1; then break; fi
  echo "waiting for object storage ($i/60)..."; sleep 2
done
aws --endpoint-url "$EP" s3 ls "s3://${AWS_BUCKET}" >/dev/null 2>&1 || aws --endpoint-url "$EP" s3 mb "s3://${AWS_BUCKET}"
echo "bucket ${AWS_BUCKET} ready (private: only the s3.json identity has access)"
