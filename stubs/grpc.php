<?php

/**
 * IDE stubs for the gRPC PHP extension.
 *
 * These classes are provided at runtime by ext-grpc inside the Docker
 * environment, but the extension may not be installed on the host where
 * the editor runs. This file exists only to help static analysis and
 * IDE autocompletion; it is not autoloaded by Composer.
 */

namespace Grpc;

if (!class_exists(ChannelCredentials::class, false)) {
    class ChannelCredentials
    {
        /**
         * @return ChannelCredentials
         */
        public static function createInsecure() {}
    }
}
