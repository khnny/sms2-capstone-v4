# Bestlink SMS2 landing page - HostForge / Docker image (static files on nginx)
FROM nginx:alpine
# The nginx image fills in ${PORT} from the environment at start-up.
ENV PORT=8000 NGINX_ENVSUBST_FILTER=^PORT$
COPY nginx.conf.template /etc/nginx/templates/default.conf.template
COPY index.html privacy.html links.js /usr/share/nginx/html/
COPY assets /usr/share/nginx/html/assets
EXPOSE 8000
