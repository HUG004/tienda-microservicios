FROM golang:1.25-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -o /out/api-inventario .

FROM alpine:3.22
RUN adduser -D app
USER app
COPY --from=build /out/api-inventario /usr/local/bin/api-inventario
ENV GIN_MODE=release
EXPOSE 8004
CMD ["api-inventario"]