mod config;
mod net;

use crate::config::Config;
use crate::net::{client::Client, server::{self, Server}};
use store_engine::store::Store as EngineStore;
use store_engine::cache::Cache;
use store_engine::codec::Codec;
use serde::Serialize;
use std::io;

fn main() {
    let _ = (Config::default(), Client {}, Server {}, EngineStore {});
}
