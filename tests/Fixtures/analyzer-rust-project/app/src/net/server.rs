use self::inner::Limit;

pub struct Server {}

pub trait Handler {
    fn handle(&self);
}
